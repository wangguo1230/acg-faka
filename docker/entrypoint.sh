#!/bin/sh
set -e

cd /var/www/html

# 这些目录都会在运行期被安装器、插件市场、上传接口、模板引擎、
# WAF/请求日志、在线更新等逻辑写入。容器启动时再修一次，是为了兼容
# 已存在的 named volume 或从旧版本 docker-compose 迁移过来的 volume。
mkdir -p \
    assets/cache \
    app/Pay \
    app/Plugin \
    app/View/User/Theme \
    config \
    kernel/Install/OS \
    kernel/Install/Update \
    runtime/log \
    runtime/plugin \
    runtime/request \
    runtime/tmp \
    runtime/view \
    runtime/waf

# 内置主题跟随镜像更新：named volume 只在首次创建时从镜像拷贝内容，之后会
# 一直遮住镜像里的新模板。每次启动时从镜像预留的纯净副本整目录替换内置主题
#（避免残留已删除的旧文件），目录名不同的用户自装主题不受影响。
# 注意：仅同步 app/View/User/Theme 与 runtime/view，app/Pay、app/Plugin
# 等运行期安装的支付/功能插件卷不做任何改动。
if [ -d /usr/local/share/acg-faka/Theme ]; then
    for theme_src in /usr/local/share/acg-faka/Theme/*/; do
        [ -d "$theme_src" ] || continue
        theme_name=$(basename "$theme_src")
        rm -rf "app/View/User/Theme/${theme_name}"
        cp -a "$theme_src" "app/View/User/Theme/${theme_name}"
    done
    # 模板已可能变更，清空编译缓存让模板引擎按需重新编译
    rm -rf runtime/view/compile runtime/view/cache
    mkdir -p runtime/view/compile runtime/view/cache
fi

# 内置插件随镜像分发：与主题同理，每次启动用镜像里的纯净副本整目录替换内置插件代码
#（镜像为唯一可信源，避免残留已删除的旧文件、或卷内被手动改坏的旧版本），但保留卷内
# 每个插件 Config/Config.php 的运行态（启用状态 STATUS、Bot Token、Chat ID 等用户配置）。
# 这样升级镜像即可自动更新内置插件代码，无需手动进卷打补丁，也不会冲掉已填配置与启用状态。
# 目录名不同的用户自装插件不受影响。
if [ -d /usr/local/share/acg-faka/Plugin ]; then
    for plugin_src in /usr/local/share/acg-faka/Plugin/*/; do
        [ -d "$plugin_src" ] || continue
        plugin_name=$(basename "$plugin_src")
        target="app/Plugin/${plugin_name}"
        keep=""
        if [ -f "${target}/Config/Config.php" ]; then
            keep=$(mktemp)
            cp "${target}/Config/Config.php" "$keep"
        fi
        rm -rf "$target"
        cp -a "$plugin_src" "$target"
        if [ -n "$keep" ]; then
            mkdir -p "${target}/Config"
            cp "$keep" "${target}/Config/Config.php"
            rm -f "$keep"
        fi
    done
fi

# 后台“基础设置”会把上传的 Logo 写到 /favicon.ico。
# 将它落到 assets/cache 这个持久化卷中，避免容器重建后丢失。
if [ ! -f assets/cache/favicon.ico ]; then
    if [ -f /usr/local/share/acg-faka/favicon.ico ]; then
        cp /usr/local/share/acg-faka/favicon.ico assets/cache/favicon.ico
    else
        : > assets/cache/favicon.ico
    fi
fi

if [ ! -L favicon.ico ]; then
    rm -f favicon.ico
    ln -s assets/cache/favicon.ico favicon.ico
fi

# 根据环境变量渲染 PHP Session(Redis) 配置，支持对接外部 Redis（如 1Panel）。
# 默认值与本地 docker-compose 中的内置 redis 服务保持一致。
REDIS_HOST="${REDIS_HOST:-redis}"
REDIS_PORT="${REDIS_PORT:-6379}"
REDIS_DATABASE="${REDIS_DATABASE:-1}"
REDIS_SESSION_PREFIX="${REDIS_SESSION_PREFIX:-acg_sess:}"

SESSION_QUERY="database=${REDIS_DATABASE}&prefix=${REDIS_SESSION_PREFIX}"
if [ -n "${REDIS_PASSWORD:-}" ]; then
    SESSION_QUERY="auth=${REDIS_PASSWORD}&${SESSION_QUERY}"
fi

cat > /usr/local/etc/php/conf.d/zz-acg-session.ini <<EOF
session.save_handler = redis
session.save_path = "tcp://${REDIS_HOST}:${REDIS_PORT}?${SESSION_QUERY}"
EOF

# 当提供数据库环境变量、且 config/database.php 仍是发行版自带的 demo 占位配置时，
# 用环境变量预置数据库连接，方便对接外部 MySQL（如 1Panel）。真实安装后的配置不会被覆盖。
if [ -n "${DB_HOST:-}" ] && { [ ! -f config/database.php ] || grep -q "'database' => 'demo'" config/database.php; }; then
    cat > config/database.php <<EOF
<?php
declare (strict_types=1);

return [
    'driver' => 'mysql',
    'host' => '${DB_HOST}',
    'database' => '${DB_DATABASE:-acg-faka}',
    'username' => '${DB_USERNAME:-acg-faka}',
    'password' => '${DB_PASSWORD:-}',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '${DB_PREFIX:-acg_}',
];
EOF
fi

chown -R www-data:www-data \
    assets/cache \
    app/Pay \
    app/Plugin \
    app/View/User/Theme \
    config \
    kernel/Install \
    runtime

# 内置插件自愈：对 Config.php 中标记为启用(STATUS=1)的插件，在每次启动时以 www-data
# 身份重建 hook 缓存。加密引擎 _plugin_start 无法正常启用自建插件、或换宿主机导致
# HWID 变化使旧缓存失效时，靠这一步让已启用插件的 hook 在启动后依旧可用。
# 以 www-data 运行以保证 HWID 与 Web 端一致；失败只跳过，绝不阻塞容器启动。
if [ -f kernel/Install/Lock ]; then
    su -s /bin/sh www-data -c "php /usr/local/bin/acg-faka-plugin-heal.php" || true

    # 守护：强制启用清单内的自建插件（如 TgNotify），其 STATUS 会被后台「保存配置 /
    # 停用」自动重置为 0，导致一段时间后后台显示「未启用」。这里周期性以 www-data
    # 跑自愈的 --if-needed 模式：仅当检测到 STATUS 被重置或 hook 缓存丢失时才纠正，
    # 平时立即退出（不连库、零开销）。后台进程在下方 exec 后由 PID 1 收养，随容器销毁。
    PLUGIN_HEAL_INTERVAL="${PLUGIN_HEAL_INTERVAL:-300}"
    (
        while true; do
            sleep "$PLUGIN_HEAL_INTERVAL"
            su -s /bin/sh www-data -c "php /usr/local/bin/acg-faka-plugin-heal.php --if-needed" >/dev/null 2>&1 || true
        done
    ) &
fi

exec docker-php-entrypoint "$@"
