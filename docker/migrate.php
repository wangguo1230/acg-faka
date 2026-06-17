<?php
/**
 * 轻量启动迁移脚本（容器启动时由 entrypoint 调用）。
 *
 * 背景：app/Controller、Model、SQL 随镜像分发，但已存在的生产数据库不会自动跟随
 * 表结构变更。本脚本在每次启动时用框架的 Schema builder（自动带表前缀）幂等地补齐
 * 新增列，使「升级镜像即完成 schema 迁移」，无需手动进库执行 ALTER。
 *
 * 原则：每条迁移都先 hasColumn/hasTable 判断，已存在即跳过；任何异常只跳过、
 * 绝不阻塞容器启动。需在应用代码读写新列之前执行。
 */
declare(strict_types=1);

const BASE_PATH = "/var/www/html";

error_reporting(0);

try {
    if (!file_exists(BASE_PATH . '/kernel/Install/Lock')) {
        fwrite(STDOUT, "[migrate] 系统未安装，跳过\n");
        exit(0);
    }

    require BASE_PATH . '/vendor/autoload.php';
    require BASE_PATH . '/kernel/Helper.php';

    $db = config('database');
    if (!$db || empty($db['host'])) {
        fwrite(STDOUT, "[migrate] 无数据库配置，跳过\n");
        exit(0);
    }

    $capsule = new \Illuminate\Database\Capsule\Manager();
    $capsule->addConnection($db);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getConnection()->getPdo(); // 连不上会抛异常，被下方 catch 跳过

    $schema = $capsule->schema();

    // 迁移：user_recharge 增加 pay_cost（支付接口手续费）列。
    // 充值到账额 = amount - pay_cost，旧库无此列时充值会因 Unknown column 写入失败。
    if ($schema->hasTable('user_recharge') && !$schema->hasColumn('user_recharge', 'pay_cost')) {
        $schema->table('user_recharge', function ($table) {
            // 紧跟 amount 之后，默认 0 使存量订单到账行为不变
            $table->decimal('pay_cost', 10, 2)->unsigned()->nullable()->default(0)->after('amount')->comment('支付接口手续费');
        });
        fwrite(STDOUT, "[migrate] user_recharge.pay_cost 已添加\n");
    }

    fwrite(STDOUT, "[migrate] 完成\n");
} catch (\Throwable $e) {
    fwrite(STDOUT, "[migrate] 跳过（" . $e->getMessage() . "）\n");
}

exit(0);
