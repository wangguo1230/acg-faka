<?php
/**
 * 容器启动迁移：把存量库从 3.5.x 补齐到当前镜像所需的 3.8.x 结构，
 * 并把旧支付插件 Config.php 导入 pay_config、绑定 pay.pay_config_id。
 *
 * 关键路径（pay_config / manage_session / 支付配置绑定）失败必须非 0 退出，
 * 由 entrypoint 阻断启动，避免再出现「容器健康、支付和后台已死」。
 *
 * 每条操作都先 hasTable/hasColumn/exists 判断，可重复执行。
 */
declare(strict_types=1);

const BASE_PATH = "/var/www/html";

error_reporting(E_ALL);
ini_set('display_errors', '0');

$fatal = [];

try {
    if (!file_exists(BASE_PATH . '/kernel/Install/Lock')) {
        fwrite(STDOUT, "[migrate] 系统未安装，跳过\n");
        exit(0);
    }

    require BASE_PATH . '/vendor/autoload.php';
    require BASE_PATH . '/kernel/Helper.php';

    $dbConfig = config('database');
    if (!$dbConfig || empty($dbConfig['host'])) {
        throw new \RuntimeException('已安装站点缺少数据库配置');
    }

    $capsule = new \Illuminate\Database\Capsule\Manager();
    $dbConfig['options'][PDO::ATTR_TIMEOUT] = 3;
    $capsule->addConnection($dbConfig);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $db = $capsule->getConnection();
    $wait = getenv('ACG_DB_WAIT_SECONDS');
    $deadline = time() + max(0, min(120, $wait === false ? 30 : (int)$wait));
    do {
        try {
            $db->getPdo();
            break;
        } catch (\Throwable $e) {
            if (time() >= $deadline) {
                throw new \RuntimeException('数据库尚未就绪，请检查连接配置和网络', 0, $e);
            }
            sleep(1);
        }
    } while (true);

    $schema = $capsule->schema();
    $db = $capsule->getConnection();
    foreach (['config', 'manage', 'order', 'user_recharge', 'pay', 'commodity', 'shared', 'user_commodity'] as $table) {
        if (!$schema->hasTable($table)) {
            throw new \RuntimeException("旧站必需表 {$table} 不存在，请核对数据库及表前缀");
        }
    }
    if (in_array('--check', $argv, true)) {
        $result = (new \App\Util\PaymentUpgrade($db, false))->run();
        foreach ($result['messages'] as $message) {
            fwrite(STDOUT, "[check] 计划：{$message}\n");
        }
        foreach ($result['errors'] as $message) {
            fwrite(STDERR, "[check] {$message}\n");
        }
        fwrite(STDOUT, "[check] 只读预检完成；DDL 权限与网关支付需在副本验收\n");
        exit($result['errors'] === [] ? 0 : 1);
    }

    $lockName = 'acg-upgrade-' . substr(hash('sha256', $db->getDatabaseName() . ':' . $db->getTablePrefix()), 0, 40);
    $lock = (array)$db->selectOne('SELECT GET_LOCK(?, 30) AS acquired', [$lockName]);
    if ((int)($lock['acquired'] ?? 0) !== 1) {
        throw new \RuntimeException('无法取得升级锁，请等待另一迁移任务完成');
    }
    register_shutdown_function(static function () use ($db, $lockName): void {
        try { $db->selectOne('SELECT RELEASE_LOCK(?)', [$lockName]); } catch (\Throwable) {}
    });

    $addColumn = static function (string $table, string $column, callable $define, bool $critical = true) use ($schema, &$fatal): void {
        if ($schema->hasColumn($table, $column)) {
            return;
        }
        try {
            $schema->table($table, $define);
            fwrite(STDOUT, "[migrate] {$table}.{$column} 已添加\n");
        } catch (\Throwable $e) {
            $msg = "[migrate] {$table}.{$column} 失败：" . $e->getMessage();
            fwrite(STDERR, $msg . "\n");
            if ($critical) {
                $fatal[] = $msg;
            }
        }
    };

    $createTable = static function (string $table, callable $define, bool $critical = true) use ($schema, &$fatal): bool {
        if ($schema->hasTable($table)) {
            return true;
        }
        try {
            $schema->create($table, $define);
            fwrite(STDOUT, "[migrate] 表 {$table} 已创建\n");
            return true;
        } catch (\Throwable $e) {
            $msg = "[migrate] 表 {$table} 创建失败：" . $e->getMessage();
            fwrite(STDERR, $msg . "\n");
            if ($critical) {
                $fatal[] = $msg;
            }
            return false;
        }
    };

    // —— 列：3.5.4 → 3.7.6 增量 ——
    $addColumn('user_recharge', 'pay_cost', static function ($t) {
        $t->decimal('pay_cost', 10, 2)->unsigned()->nullable()->default(0);
    });
    $addColumn('user_recharge', 'gateway_amount', static function ($t) {
        $t->decimal('gateway_amount', 10, 2)->unsigned()->nullable();
    });
    $addColumn('order', 'gateway_amount', static function ($t) {
        $t->decimal('gateway_amount', 10, 2)->unsigned()->nullable();
    });
    $addColumn('order', 'leave_message', static function ($t) {
        $t->text('leave_message')->nullable();
    });
    $addColumn('pay', 'pay_config_id', static function ($t) {
        $t->unsignedInteger('pay_config_id')->default(0);
    }, true);
    $addColumn('pay', 'archived', static function ($t) {
        $t->unsignedTinyInteger('archived')->default(0);
    });
    $addColumn('commodity', 'shared_premium_template', static function ($t) {
        $t->unsignedInteger('shared_premium_template')->default(0);
    });
    $addColumn('commodity', 'tags', static function ($t) {
        $t->string('tags', 1000)->nullable();
    });
    $addColumn('shared', 'currency', static function ($t) {
        $t->string('currency', 8)->default('CNY');
    });
    $addColumn('shared', 'currency_rate', static function ($t) {
        $t->decimal('currency_rate', 18, 6)->default(0);
    });
    $addColumn('shared', 'protocol', static function ($t) {
        $t->unsignedTinyInteger('protocol')->default(0);
    });
    $addColumn('user_commodity', 'rounding', static function ($t) {
        $t->unsignedTinyInteger('rounding')->default(0);
    });
    $addColumn('user_commodity', 'description', static function ($t) {
        $t->text('description')->nullable();
    });
    //转账蜜罐计数。必须落库而不是走 Throttle：后者是滑动窗口，窗口一过计数归零，
    //攻击者等过期就能重新拿满次数，封禁阈值形同虚设；且其缓存异常时 fail-open。
    $addColumn('user', 'risk_transfer_count', static function ($t) {
        $t->unsignedInteger('risk_transfer_count')->default(0);
    });

    // —— 列：3.7.7 → 3.8.1 增量（商品管控 / 会员两步验证 / 后台闲置锁屏）——
    $addColumn('commodity', 'substation_disable', static function ($t) {
        $t->unsignedTinyInteger('substation_disable')->default(0);
    });
    $addColumn('commodity', 'ban', static function ($t) {
        $t->unsignedTinyInteger('ban')->default(0);
    });
    $addColumn('commodity', 'ban_reason', static function ($t) {
        $t->string('ban_reason', 255)->nullable();
    });
    $addColumn('user', 'totp_secret', static function ($t) {
        $t->string('totp_secret', 64)->nullable();
    });
    $addColumn('user', 'totp_recovery', static function ($t) {
        $t->text('totp_recovery')->nullable();
    });
    $addColumn('user', 'fund_2fa', static function ($t) {
        $t->unsignedTinyInteger('fund_2fa')->default(0);
    });

    // —— 新表 ——
    $createTable('pay_config', static function ($t) {
        $t->increments('id');
        $t->string('handle', 64);
        $t->string('name', 32);
        $t->mediumText('config')->nullable();
        $t->unsignedSmallInteger('sort')->default(0);
        $t->dateTime('create_time');
        $t->dateTime('update_time')->nullable();
        $t->unique(['handle', 'name'], 'handle_name');
        $t->index('handle');
    }, true);

    $createTable('manage_session', static function ($t) {
        $t->bigIncrements('id');
        $t->unsignedInteger('manage_id');
        $t->char('session_hash', 64);
        $t->string('device_type', 16);
        $t->string('device_name', 96);
        $t->string('user_agent', 512);
        $t->string('login_ip', 45);
        $t->string('last_ip', 45);
        $t->dateTime('created_time');
        $t->dateTime('last_seen_time');
        $t->dateTime('expires_time');
        $t->dateTime('revoked_time')->nullable();
        $t->unique('session_hash');
        $t->index(['manage_id', 'revoked_time', 'expires_time'], 'manage_active');
        $t->index('last_seen_time');
    }, true);

    //外键与建表分开：存量库主表列类型可能与 Install.sql 不一致，外键失败只记录、不阻断启动。
    $ensureForeign = static function (string $table, string $column, string $refTable) use ($schema, $db): void {
        if (!$schema->hasTable($table) || !$schema->hasTable($refTable)) {
            return;
        }
        $name = $table . '_ibfk_1';
        try {
            $rows = $db->select(
                "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                [$db->getTablePrefix() . $table]
            );
            $have = [];
            foreach ($rows as $row) {
                $row = (array)$row;
                $have[] = (string)($row['CONSTRAINT_NAME'] ?? $row['constraint_name'] ?? '');
            }
            if (array_intersect([$name, $db->getTablePrefix() . $name], $have) !== []) {
                return;
            }
        } catch (\Throwable) {
        }
        try {
            $schema->table($table, static function ($t) use ($column, $refTable, $name) {
                $t->foreign($column, $name)->references('id')->on($refTable)->onDelete('cascade');
            });
            fwrite(STDOUT, "[migrate] {$table} 外键已添加\n");
        } catch (\Throwable $e) {
            fwrite(STDOUT, "[migrate] {$table} 外键跳过（" . $e->getMessage() . "）\n");
        }
    };
    $ensureForeign('manage_session', 'manage_id', 'manage');

    // —— 3.7.7 → 3.8.1 新表：后台闲置锁屏 / 通行密钥 / 会员会话与安全日志 ——
    $addColumn('manage_session', 'last_active_time', static function ($t) {
        $t->dateTime('last_active_time')->nullable();
    });

    $webauthn = static function (string $owner): callable {
        return static function ($t) use ($owner) {
            $t->bigIncrements('id');
            $t->unsignedInteger($owner);
            $t->string('credential_id', 255);
            $t->text('public_key');
            $t->unsignedBigInteger('sign_count')->default(0);
            $t->string('transports', 128)->nullable();
            $t->string('aaguid', 64)->nullable();
            $t->string('name', 64)->default('');
            $t->dateTime('created_time');
            $t->dateTime('last_used_time')->nullable();
            $t->string('last_used_ip', 45)->nullable();
            $t->unique('credential_id', 'credential_id');
            $t->index($owner, $owner);
        };
    };
    $createTable('manage_webauthn', $webauthn('manage_id'));
    $ensureForeign('manage_webauthn', 'manage_id', 'manage');
    $createTable('user_webauthn', $webauthn('user_id'));
    $ensureForeign('user_webauthn', 'user_id', 'user');

    $createTable('user_session', static function ($t) {
        $t->bigIncrements('id');
        $t->unsignedInteger('user_id');
        $t->char('session_hash', 64);
        $t->string('device_type', 16);
        $t->string('device_name', 96);
        $t->string('user_agent', 512);
        $t->string('login_ip', 45);
        $t->string('last_ip', 45);
        $t->dateTime('created_time');
        $t->dateTime('last_seen_time');
        $t->dateTime('expires_time');
        $t->dateTime('revoked_time')->nullable();
        $t->unique('session_hash', 'session_hash');
        $t->index(['user_id', 'revoked_time', 'expires_time'], 'user_active');
        $t->index('last_seen_time', 'last_seen_time');
    });
    $ensureForeign('user_session', 'user_id', 'user');

    $createTable('user_log', static function ($t) {
        $t->bigIncrements('id');
        $t->unsignedInteger('user_id');
        $t->string('username', 32)->default('');
        $t->string('action', 32);
        $t->string('content', 255)->default('');
        $t->dateTime('create_time');
        $t->string('create_ip', 64);
        $t->string('ua', 255)->nullable();
        $t->unsignedTinyInteger('risk')->default(0);
        $t->index('user_id', 'user_id');
        $t->index('create_time', 'create_time');
        $t->index('action', 'action');
        $t->index('risk', 'risk');
    });
    $ensureForeign('user_log', 'user_id', 'user');

    $createTable('lang', static function ($t) {
        $t->increments('id');
        $t->char('hash', 32);
        $t->text('source');
        $t->string('lang', 8);
        $t->text('text')->nullable();
        $t->string('scene', 32)->default('');
        $t->unsignedTinyInteger('status')->default(0);
        $t->dateTime('create_time')->nullable();
        $t->dateTime('update_time')->nullable();
        $t->unique(['hash', 'lang'], 'uk_hash_lang');
        $t->index(['lang', 'status'], 'idx_lang_status');
    });

    $createTable('price_template', static function ($t) {
        $t->increments('id');
        $t->string('name', 64);
        $t->unsignedTinyInteger('base')->default(0);
        $t->unsignedTinyInteger('guest_type')->default(1);
        $t->decimal('guest_value', 10, 2)->default(0);
        $t->unsignedTinyInteger('user_type')->default(1);
        $t->decimal('user_value', 10, 2)->default(0);
        $t->text('level_config')->nullable();
        $t->unsignedTinyInteger('rounding')->default(0);
        $t->dateTime('create_time');
    });

    $createTable(\App\Util\PaymentUpgrade::MIGRATIONS, static function ($t) {
        $t->string('version', 128)->primary();
        $t->dateTime('completed_at');
    });
    $createTable(\App\Util\LegacyPayCallback::TABLE, static function ($t) {
        $t->unsignedInteger('pay_id')->primary();
        $t->string('handle', 64);
        $t->mediumText('config');
        $t->unsignedInteger('order_max_id')->default(0);
        $t->unsignedInteger('recharge_max_id')->default(0);
        $t->dateTime('create_time');
    });

    // —— 新配置键（缺了 Config::get 会返回空串，多数有代码兜底，这里补上以免后台表单空白）——
    if ($schema->hasTable('config')) {
        $configDefaults = [
            'callback_ip_whitelist' => '0',
            'callback_ip_whitelist_rules' => '',
            'force_login' => '0',
            'admin_login_verification' => '1',
            'request_log' => '0',
            'admin_entrance' => '',
            'lang_version' => '0',
            'currency_code' => 'CNY',
            'currency_symbol' => '¥',
            'currency_rate' => '1',
            'currency_decimals' => '2',
            // 老站升级默认 report：与 Csp::mode() 在键缺失时的行为一致。
            // enforce 是全新安装 Install.sql 的值，套到存量站会拦主题/插件外链脚本。
            'csp_mode' => 'report',
            // 与 AdminLock 缺键时的默认一致（15 分钟闲置锁屏），写入只为后台表单不空白。
            'admin_lock_timeout' => '15',
        ];
        foreach ($configDefaults as $key => $value) {
            try {
                if (!$db->table('config')->where('key', $key)->exists()) {
                    $db->table('config')->insert(['key' => $key, 'value' => $value]);
                    fwrite(STDOUT, "[migrate] config.{$key} 已写入默认值\n");
                }
            } catch (\Throwable $e) {
                $fatal[] = "config.{$key} 写入失败";
            }
        }
    }

    if ($fatal === []) {
        $result = (new \App\Util\PaymentUpgrade($db))->run();
        foreach ($result['messages'] as $message) {
            fwrite(STDOUT, "[migrate] {$message}\n");
        }
        $fatal = array_merge($fatal, $result['errors']);
    }

    if ($fatal !== []) {
        fwrite(STDERR, "[migrate] 关键迁移失败，拒绝启动：\n" . implode("\n", $fatal) . "\n");
        exit(1);
    }

    $db->table(\App\Util\PaymentUpgrade::MIGRATIONS)->updateOrInsert(
        ['version' => \App\Util\PaymentUpgrade::VERSION],
        ['completed_at' => date('Y-m-d H:i:s')]
    );
    // DB defaults were inserted directly; discard only the derived config cache.
    $configCache = BASE_PATH . '/runtime/config';
    if (is_file($configCache) && !unlink($configCache)) {
        throw new \RuntimeException('无法刷新配置缓存，请检查 runtime 权限');
    }
    fwrite(STDOUT, "[migrate] 完成\n");
} catch (\Throwable $e) {
    fwrite(STDERR, "[migrate] 失败（" . $e->getMessage() . "）\n");
    exit(1);
}

exit(0);
