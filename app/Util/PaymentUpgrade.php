<?php
declare(strict_types=1);

namespace App\Util;

use Illuminate\Database\Connection;

/** Payment data migration shared by container startup and the read-only preflight. */
final class PaymentUpgrade
{
    public const VERSION = 'docker-3.7.6-pay-v2';
    public const MIGRATIONS = 'docker_migration';

    private array $messages = [];
    private array $errors = [];
    private bool $completed;

    public function __construct(private Connection $db, private bool $apply = true)
    {
        $this->completed = $db->getSchemaBuilder()->hasTable(self::MIGRATIONS)
            && $db->table(self::MIGRATIONS)->where('version', self::VERSION)->exists();
    }

    /** @return array{messages:array,errors:array} */
    public function run(): array
    {
        $groups = $this->db->table('pay')->where('handle', '<>', '#system')->get()->groupBy('handle');
        foreach ($groups as $handle => $payments) {
            $handle = (string)$handle;
            try {
                $work = function () use ($handle, $payments): void {
                    $this->migrateHandle($handle, $payments->all());
                };
                $this->apply ? $this->db->transaction($work) : $work();
            } catch (\Throwable $e) {
                // QueryException messages include bindings, which can contain merchant secrets.
                $this->errors[] = "插件 {$handle} 支付迁移数据库操作失败，请检查权限和表约束（" . get_class($e) . '）';
            }
        }
        return ['messages' => $this->messages, 'errors' => $this->errors];
    }

    private function migrateHandle(string $handle, array $payments): void
    {
        $schema = $this->db->getSchemaBuilder();
        $profiles = $schema->hasTable('pay_config')
            ? $this->db->table('pay_config')->where('handle', $handle)->orderBy('id')->get()->keyBy('id')->all()
            : [];
        $snapshots = $schema->hasTable(LegacyPayCallback::TABLE)
            ? $this->db->table(LegacyPayCallback::TABLE)->where('handle', $handle)->get()->keyBy('pay_id')->all()
            : [];
        $legacy = null;
        $readLegacy = function () use ($handle, &$legacy): array {
            return $legacy ??= $this->readLegacy($handle);
        };

        foreach ($payments as $pay) {
            $id = (int)$pay->id;
            $active = (int)($pay->archived ?? 0) === 0 && ((int)$pay->commodity === 1 || (int)$pay->recharge === 1);
            $configId = (int)($pay->pay_config_id ?? 0);
            $profile = $profiles[$configId] ?? null;
            $values = $profile ? self::decode((string)$profile->config) : [];

            if (!self::hasValues($values)) {
                if ($configId > 0 && $profile === null) {
                    $this->problem($id, $handle, $active, '配置 ID 不存在或属于其他插件，未自动更换商户');
                    continue;
                }

                $source = $readLegacy();
                // Repair only the unique empty default left by the earlier incomplete migration.
                $emptyDefault = !$this->completed && count($profiles) === 1
                    && (string)reset($profiles)->name === '默认配置'
                    && !self::hasValues(self::decode((string)reset($profiles)->config));
                if ($configId > 0 && !$emptyDefault) {
                    $this->problem($id, $handle, $active, '所绑定配置为空，未覆盖已选择的配置');
                    continue;
                }
                if (self::hasValues($source)) {
                    $matching = null;
                    foreach ($profiles as $candidate) {
                        if (self::decode((string)$candidate->config) == $source) {
                            $matching = $candidate;
                            break;
                        }
                    }
                    if ($matching) {
                        $profile = $matching;
                    } elseif ($emptyDefault) {
                        $profile = reset($profiles);
                        if ($this->apply) {
                            $this->db->table('pay_config')->where('id', $profile->id)->update([
                                'config' => self::encode($source), 'update_time' => date('Y-m-d H:i:s'),
                            ]);
                        }
                        $profile->config = self::encode($source);
                        $this->messages[] = "插件 {$handle} 的唯一空默认配置已从旧文件恢复";
                    } else {
                        $names = array_map(static fn($row) => (string)$row->name, $profiles);
                        $name = '旧站迁入配置';
                        for ($suffix = 2; in_array($name, $names, true); $suffix++) {
                            $name = '旧站迁入配置 ' . $suffix;
                        }
                        $record = ['handle' => $handle, 'name' => $name, 'config' => self::encode($source),
                            'sort' => 0, 'create_time' => date('Y-m-d H:i:s'), 'update_time' => null];
                        $newId = $this->apply ? $this->db->table('pay_config')->insertGetId($record) : -1;
                        $profile = (object)(['id' => $newId] + $record);
                        $this->messages[] = "插件 {$handle} 导入旧支付配置";
                    }
                    $profiles[(int)$profile->id] = $profile;
                    $values = $source;
                } elseif ($configId === 0 && count($profiles) === 1 && self::hasValues(self::decode((string)reset($profiles)->config))) {
                    $profile = reset($profiles);
                    $values = self::decode((string)$profile->config);
                } else {
                    $this->problem($id, $handle, $active, '没有可唯一确定的旧商户配置');
                    continue;
                }

                if ($configId === 0) {
                    $configId = (int)$profile->id;
                    if ($this->apply) {
                        $this->db->table('pay')->where('id', $id)->where('pay_config_id', 0)->update(['pay_config_id' => $configId]);
                    }
                    $this->messages[] = "接口 #{$id} ({$handle}) 绑定原商户配置";
                }
            }

            if ($active && !$this->pluginAvailable($handle)) {
                $this->problem($id, $handle, true, '支付插件代码或 Info.php 缺失');
            }

            if (!isset($snapshots[$id])) {
                $limits = [];
                foreach (['order' => 'order_max_id', 'user_recharge' => 'recharge_max_id'] as $table => $column) {
                    $query = $this->db->table($table)->where('pay_id', $id);
                    if ($schema->hasColumn($table, 'gateway_amount')) {
                        $query->whereNull('gateway_amount');
                    }
                    $limits[$column] = (int)$query->max('id');
                }
                // Record zero limits too: a restart must never extend the legacy window
                // to orders subsequently created by a compatibility rollback release.
                $source = $readLegacy();
                $snapshot = ['pay_id' => $id, 'handle' => $handle,
                    'config' => self::encode(self::hasValues($source) ? $source : $values),
                    'create_time' => date('Y-m-d H:i:s')] + $limits;
                if ($this->apply) {
                    $this->db->table(LegacyPayCallback::TABLE)->insert($snapshot);
                }
                $snapshots[$id] = (object)$snapshot;
                $this->messages[] = "接口 #{$id} ({$handle}) 已保留旧订单回调范围及凭据";
            }
        }
    }

    private function problem(int $id, string $handle, bool $active, string $reason): void
    {
        $message = "接口 #{$id} ({$handle})：{$reason}";
        if ($active) {
            $this->errors[] = $message;
        } else {
            $pending = $this->db->table('order')->where('pay_id', $id)->where('status', 0)->count()
                + $this->db->table('user_recharge')->where('pay_id', $id)->where('status', 0)->count();
            $this->messages[] = "提示 {$message}；保持停用，关联未完成订单 {$pending} 笔，不阻断启动";
        }
    }

    private function readLegacy(string $handle): array
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $handle)) {
            return [];
        }
        $root = realpath(BASE_PATH . '/app/Pay');
        $file = realpath(BASE_PATH . '/app/Pay/' . $handle . '/Config/Config.php');
        if (!$root || !$file || !is_file($file) || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
            return [];
        }
        try {
            $loaded = (static fn($path) => include $path)($file);
            return is_array($loaded) ? self::normalize($loaded) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function pluginAvailable(string $handle): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $handle) === 1
            && PayConfig::isValid($handle)
            && is_file(BASE_PATH . '/app/Pay/' . $handle . '/Config/Info.php');
    }

    private static function normalize(array $values): array
    {
        $protected = ['id', 'handle', 'plugin', 'plugin_id', 'plugin_key', 'status', 'name', 'author', 'create_time', 'top'];
        $result = [];
        foreach ($values as $key => $value) {
            $key = trim((string)$key);
            if ($key !== '' && !in_array(strtolower($key), $protected, true) && (is_scalar($value) || $value === null)) {
                $result[$key] = (string)($value ?? '');
            }
        }
        return $result;
    }

    private static function decode(string $json): array
    {
        $values = json_decode($json, true);
        return is_array($values) ? self::normalize($values) : [];
    }

    private static function hasValues(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string)$value) !== '') {
                return true;
            }
        }
        return false;
    }

    private static function encode(array $values): string
    {
        return json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
