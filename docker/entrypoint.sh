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

exec docker-php-entrypoint "$@"
