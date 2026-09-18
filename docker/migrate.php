<?php
/**
 * 容器启动迁移：把存量库从 3.5.x 补齐到当前镜像所需的 3.7.x 结构，
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
        fwrite(STDOUT, "[migrate] 无数据库配置，跳过\n");
        exit(0);
    }

    $capsule = new \Illuminate\Database\Capsule\Manager();
    $capsule->addConnection($dbConfig);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getConnection()->getPdo();

    $schema = $capsule->schema();
    $db = $capsule->getConnection();
    $now = date('Y-m-d H:i:s');

    $addColumn = static function (string $table, string $column, callable $define, bool $critical = false) use ($schema, &$fatal): void {
        if (!$schema->hasTable($table) || $schema->hasColumn($table, $column)) {
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

    $createTable = static function (string $table, callable $define, bool $critical = false) use ($schema, &$fatal): bool {
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

    if ($schema->hasTable('manage_session') && $schema->hasTable('manage')) {
        try {
            $schema->table('manage_session', static function ($t) {
                $t->foreign('manage_id', 'manage_session_ibfk_1')
                    ->references('id')->on('manage')
                    ->onDelete('cascade');
            });
            fwrite(STDOUT, "[migrate] manage_session 外键已添加\n");
        } catch (\Throwable $e) {
            fwrite(STDOUT, "[migrate] manage_session 外键跳过（" . $e->getMessage() . "）\n");
        }
    }

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
            'csp_mode' => 'enforce',
        ];
        foreach ($configDefaults as $key => $value) {
            try {
                if (!$db->table('config')->where('key', $key)->exists()) {
                    $db->table('config')->insert(['key' => $key, 'value' => $value]);
                    fwrite(STDOUT, "[migrate] config.{$key} 已写入默认值\n");
                }
            } catch (\Throwable $e) {
                fwrite(STDERR, "[migrate] config.{$key} 失败：" . $e->getMessage() . "\n");
            }
        }
    }

    // —— 把旧 Config.php 导入 pay_config，并绑到尚未选择配置的支付接口 ——
    if (!$schema->hasTable('pay_config') || !$schema->hasTable('pay') || !$schema->hasColumn('pay', 'pay_config_id')) {
        $fatal[] = '[migrate] 支付配置表或 pay.pay_config_id 不存在，无法完成绑定';
    } else {
        $handles = [];
        foreach ($db->table('pay')->select('handle')->distinct()->pluck('handle') as $handle) {
            $handle = (string)$handle;
            if ($handle !== '' && $handle !== '#system') {
                $handles[$handle] = true;
            }
        }
        $payRoot = BASE_PATH . '/app/Pay';
        if (is_dir($payRoot)) {
            foreach (scandir($payRoot) ?: [] as $name) {
                if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $name)) {
                    continue;
                }
                if (is_dir($payRoot . '/' . $name . '/Config')) {
                    $handles[$name] = true;
                }
            }
        }

        $protected = ['id', 'handle', 'plugin', 'plugin_id', 'plugin_key', 'status', 'name', 'author', 'create_time', 'top'];

        foreach (array_keys($handles) as $handle) {
            try {
                $existing = $db->table('pay_config')
                    ->where('handle', $handle)
                    ->orderBy('sort')
                    ->orderBy('id')
                    ->first();

                if ($existing) {
                    $configId = (int)$existing->id;
                } else {
                    $values = [];
                    $configFile = $payRoot . '/' . $handle . '/Config/Config.php';
                    if (is_file($configFile) && !is_link($configFile)) {
                        $loaded = @include $configFile;
                        if (is_array($loaded)) {
                            foreach ($loaded as $key => $value) {
                                $key = trim((string)$key);
                                if ($key === '' || in_array(strtolower($key), $protected, true)) {
                                    continue;
                                }
                                if (!is_scalar($value) && $value !== null) {
                                    continue;
                                }
                                $values[$key] = (string)($value ?? '');
                            }
                        }
                    }

                    $configId = $db->table('pay_config')->insertGetId([
                        'handle' => $handle,
                        'name' => '默认配置',
                        'config' => json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'sort' => 0,
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);
                    fwrite(STDOUT, "[migrate] 已从插件 {$handle} 导入默认支付配置 #{$configId}\n");
                }

                $validIds = $db->table('pay_config')->where('handle', $handle)->pluck('id')->all();
                $query = $db->table('pay')->where('handle', $handle);
                if ($validIds !== []) {
                    $query->where(function ($q) use ($validIds) {
                        $q->where('pay_config_id', 0)->orWhereNotIn('pay_config_id', $validIds);
                    });
                } else {
                    $query->where('pay_config_id', 0);
                }
                $bound = $query->update(['pay_config_id' => $configId]);
                if ($bound > 0) {
                    fwrite(STDOUT, "[migrate] 已为插件 {$handle} 绑定 {$bound} 个支付接口到配置 #{$configId}\n");
                }
            } catch (\Throwable $e) {
                $msg = "[migrate] 插件 {$handle} 支付配置迁移失败：" . $e->getMessage();
                fwrite(STDERR, $msg . "\n");
                $fatal[] = $msg;
            }
        }

        $unbound = (int)$db->table('pay')->where('handle', '<>', '#system')->where('pay_config_id', 0)->count();
        if ($unbound > 0) {
            $fatal[] = "[migrate] 仍有 {$unbound} 个支付接口 pay_config_id=0";
        }
    }

    if ($fatal !== []) {
        fwrite(STDERR, "[migrate] 关键迁移失败，拒绝启动：\n" . implode("\n", $fatal) . "\n");
        exit(1);
    }

    fwrite(STDOUT, "[migrate] 完成\n");
} catch (\Throwable $e) {
    fwrite(STDERR, "[migrate] 失败（" . $e->getMessage() . "）\n");
    exit(1);
}

exit(0);
