<?php
/**
 * 内置插件自愈脚本（由 entrypoint 调用）。
 *
 * 背景：加密引擎 kernel/Plugin.php 的 _plugin_start() 对自建插件会静默拒绝，
 * 无法在后台/CLI 正常「启用」；此外换宿主机会导致 HWID 变化，使旧的 hook 缓存
 * （按 HWID 加密）失效。本脚本对 Config.php 中已标记启用（STATUS=1）的插件，
 * 以及强制启用清单内的插件，用当前 HWID 从零重建 hook 缓存，保证钩子始终可用。
 *
 * 两种运行模式：
 *   - 默认（启动时调用）：无条件重建 hook 缓存，顺带修复换机器/HWID 变化/缓存损坏。
 *   - --if-needed（守护循环周期调用）：仅当强制清单插件的 STATUS 被后台「保存配置/
 *     停用」重置为 0、或 hook 缓存丢失时才执行重建；否则立即退出，平时零开销
 *     （连框架与数据库都不加载）。用于把被后台动作重置掉的启用状态自动恢复。
 *
 * 原则：任何异常都只跳过、绝不阻塞容器启动；只在需要时把强制清单插件的 STATUS
 * 写回 1（保留 Bot Token 等其余配置）。需以 www-data 身份运行，确保 HWID 与 Web 端一致。
 */
declare(strict_types=1);

const DEBUG = false;
const BASE_PATH = "/var/www/html";

error_reporting(0);

// 守护模式：仅在检测到强制清单插件被重置时才真正干活
$daemon = in_array('--if-needed', $argv, true);

// 强制启用清单：这些自建插件无法通过加密引擎 _plugin_start 正常启用，
// 自愈时无视 Config.php 里的 STATUS，强制置为启用并重建 hook。
$forceEnable = ['TgNotify'];
$pluginDir = BASE_PATH . '/app/Plugin';
$hookCache = BASE_PATH . '/runtime/plugin/hook';

try {
    if (!file_exists(BASE_PATH . '/kernel/Install/Lock')) {
        fwrite(STDOUT, "[plugin-heal] 系统未安装，跳过\n");
        exit(0);
    }

    // 守护模式快速跳过：稳态下（强制清单都已启用且 hook 缓存在）无需加载框架/连库/重建。
    if ($daemon) {
        $needHeal = !is_file($hookCache);
        foreach ($forceEnable as $name) {
            $cfgFile = "$pluginDir/$name/Config/Config.php";
            $cfg = is_file($cfgFile) ? @include $cfgFile : null;
            if (!is_array($cfg) || (int)($cfg['STATUS'] ?? 0) !== 1) {
                $needHeal = true; // STATUS 被后台重置为 0，需要纠正
                break;
            }
        }
        if (!$needHeal) {
            exit(0);
        }
    }

    require BASE_PATH . '/vendor/autoload.php';
    require BASE_PATH . '/kernel/Helper.php';

    if (!interface_exists(\App\Service\App::class)) {
        exit(0);
    }

    define("BASE_APP_SERVER", \App\Service\App::MAIN_SERVER);
    define("APP_VERSION", config('app')['version'] ?? '1.0.0');

    $db = config('database');
    if (!$db || empty($db['host'])) {
        fwrite(STDOUT, "[plugin-heal] 无数据库配置，跳过\n");
        exit(0);
    }

    $capsule = new \Illuminate\Database\Capsule\Manager();
    $capsule->addConnection($db);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getConnection()->getPdo(); // 连不上数据库会抛异常，被下方 catch 跳过

    \Kernel\Util\Context::set(\Kernel\Consts\Base::IS_INSTALL, true);
    \Kernel\Util\Context::set(\Kernel\Consts\Base::STORE_STATUS, true);
    \Kernel\Util\Context::set(\Kernel\Consts\Base::OPCACHE, false);
    \Kernel\Util\Context::set(\Kernel\Consts\Base::LOCK, (string)@file_get_contents(BASE_PATH . '/kernel/Install/Lock'));
    // 注意：route 首段不能是 plugin，否则 Hook::load 会触发前台跳转逻辑
    \Kernel\Util\Context::set(\Kernel\Consts\Base::ROUTE, "/cli/plugin/heal");

    require BASE_PATH . '/kernel/Plugin.php';

    if (!function_exists('_plugin_hook_add')) {
        fwrite(STDOUT, "[plugin-heal] 插件引擎未就绪，跳过\n");
        exit(0);
    }

    // 收集要重建 hook 的插件：Config 中 STATUS=1 的 + 强制清单里的
    $enabled = [];
    foreach ((array)@scandir($pluginDir) as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $cfgFile = "$pluginDir/$name/Config/Config.php";
        if (!is_file($cfgFile)) {
            continue;
        }
        $cfg = @include $cfgFile;
        if (!is_array($cfg)) {
            continue;
        }
        $isEnabled = (int)($cfg['STATUS'] ?? 0) === 1;
        if (in_array($name, $forceEnable, true) && !$isEnabled) {
            $cfg['STATUS'] = 1;
            setConfig($cfg, $cfgFile); // 强制写启用（保留 token 等其余配置），再重建 hook
            fwrite(STDOUT, "[plugin-heal] 强制启用 $name\n");
            $isEnabled = true;
        }
        if ($isEnabled) {
            $enabled[] = $name;
        }
    }

    if (!$enabled) {
        fwrite(STDOUT, "[plugin-heal] 无需重建的插件\n");
        exit(0);
    }

    // 从零重建 hook 缓存：用当前 HWID 重写，顺带修复换机器/缓存损坏
    @unlink($hookCache);

    $healed = [];
    foreach ($enabled as $name) {
        try {
            _plugin_hook_add($name);
            $healed[] = $name;
        } catch (\Throwable $e) {
            fwrite(STDOUT, "[plugin-heal] 跳过 $name（" . $e->getMessage() . "）\n");
        }
    }

    fwrite(STDOUT, "[plugin-heal] 已重建 hook 的插件：" . (implode(', ', $healed) ?: '无') . "\n");
} catch (\Throwable $e) {
    fwrite(STDOUT, "[plugin-heal] 跳过（" . $e->getMessage() . "）\n");
}

exit(0);
