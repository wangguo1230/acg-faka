<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\Config;
use App\Model\User;

/**
 * 转账蜜罐的开关与配额。
 *
 * 余额转账的亚分金额（如 0.005）会被 decimal(14,2) 隐式四舍五入：扣款方下舍回原值、
 * 收款方上进位成 0.01，形成「凭空造币」的棘轮。Bill::create 的精度闸已经把它堵死，
 * 本类负责的是另一件事——受控地重新放行有限次数，用来识别谁在刷。
 *
 * 只钓「亚分自转」（自己转给自己，见 AgentMember::honeypot）：铸币方=收款方=被封方三者
 * 同一账号，所以按固定阈值直接封禁不会误伤/嫁祸他人；亚分转给他人则直接拒绝、不铸币。
 *
 * 默认关闭。开启后每笔放行都有成本（每次 0.01 元），所以必须同时有两道闸：
 *   1. 单账号阈值：达到即封号（计数落在 user.risk_transfer_count，持久、不滑动）
 *   2. 全站总量：注册开放时单账号阈值限不住总量，触顶即整体失效（reserveQuota 原子占用）
 */
final class TransferHoneypot
{
    public const ENABLED_CONFIG = 'transfer_honeypot_enabled';
    public const LIMIT_CONFIG = 'transfer_honeypot_limit';
    public const TOTAL_CONFIG = 'transfer_honeypot_total';
    public const USED_CONFIG = 'transfer_honeypot_used';

    /** 单账号默认阈值：第 N 次尝试即封号 */
    public const DEFAULT_LIMIT = 10;

    /** 全站默认总量上限，按 0.01/次 计即最坏 5 元 */
    public const DEFAULT_TOTAL = 500;

    private const MAX_LIMIT = 1000;
    private const MAX_TOTAL = 100000;

    /**
     * 金额是否带亚分（超过两位小数）。判据与 Bill::create 的精度闸一致：
     * %.8F 固定记数避免科学计数法（1.0E-5）被误判为合法。
     */
    public static function isSubCent(mixed $amount): bool
    {
        if (!is_numeric($amount)) {
            return false;
        }
        $fixed = sprintf('%.8F', (float)$amount);
        return bccomp($fixed, bcadd($fixed, '0', 2), 8) !== 0;
    }

    public static function enabled(): bool
    {
        try {
            //键不存在=关闭。蜜罐是要真实花钱的特性，必须由站长显式打开
            return (string)(Config::cached(self::ENABLED_CONFIG) ?? '0') === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 单账号封禁阈值，站长可配。
     */
    public static function limit(): int
    {
        return self::readInt(self::LIMIT_CONFIG, self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
    }

    /**
     * 全站放行总量上限。
     */
    public static function total(): int
    {
        return self::readInt(self::TOTAL_CONFIG, self::DEFAULT_TOTAL, 0, self::MAX_TOTAL);
    }

    /**
     * 已消耗的全站配额。
     */
    public static function used(): int
    {
        return self::readInt(self::USED_CONFIG, 0, 0, PHP_INT_MAX);
    }

    /**
     * 原子占用一格全站配额：读-判-写在同一把排他锁内完成，成功返回 true。
     *
     * 取代旧的 exhausted()+consume() 两段式。旧实现有两处缺陷：
     *   1. exhausted() 在事务前判断、consume() 在事务后自增，二者非原子，并发卡在
     *      配额边界时会超发；
     *   2. consume() 用 Config::get() 读的是请求级 Context 快照（陈旧值），并发下多个
     *      请求读到同一基数、各自 +1，总量会被显著少记。
     * 这里在锁内**直接查库**拿已提交的最新值，一次完成判断与自增。读失败或总量不可解一律
     * 返回 false（fail-closed，宁可蜜罐失效也不失控）。占用成功后即使后续铸币失败，这一格
     * 也算白送——与旧 consume() 的记账语义一致，不回滚业务。
     */
    public static function reserveQuota(): bool
    {
        try {
            return (bool)Config::withExclusiveLock(static function (): bool {
                //绕过 cached()/get() 的 Context 快照，直读数据库已提交值。锁内读写串行，无竞态。
                $totalRaw = Config::query()->where('key', self::TOTAL_CONFIG)->value('value');
                //总量读不出来或非数字：无法判定上限，按耗尽处理，不放行。
                if ($totalRaw === null || !ctype_digit(trim((string)$totalRaw))) {
                    return false;
                }
                $total = (int)$totalRaw;

                $usedRaw = Config::query()->where('key', self::USED_CONFIG)->value('value');
                //空/未设置是合法初值 0；其余非数字视为读失败，按耗尽处理。
                if ($usedRaw === null || $usedRaw === '') {
                    $used = 0;
                } elseif (ctype_digit(trim((string)$usedRaw))) {
                    $used = (int)$usedRaw;
                } else {
                    return false;
                }

                if ($used >= $total) {
                    return false;
                }
                Config::put(self::USED_CONFIG, (string)($used + 1));
                return true;
            });
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 记一次尝试并返回该账号的累计次数。
     *
     * 原子自增且**独立于转账事务**：放在事务里的话，后置的余额不足校验一旦回滚会把计数
     * 一并抹掉，攻击者把余额控在不足线上就能无限试探而永远触不到封禁阈值。
     */
    public static function countAttempt(int $userId): int
    {
        User::query()->whereKey($userId)->increment('risk_transfer_count');
        return (int)User::query()->whereKey($userId)->value('risk_transfer_count');
    }

    /**
     * 达阈值封号。与 countAttempt 同样独立于转账事务。
     */
    public static function ban(int $userId): void
    {
        User::query()->whereKey($userId)->update(['status' => 0]);
    }

    /**
     * 清零全站已用配额（站长在后台重新开启蜜罐时用）。
     */
    public static function reset(): void
    {
        try {
            Config::put(self::USED_CONFIG, '0');
        } catch (\Throwable) {
        }
    }

    private static function readInt(string $key, int $default, int $min, int $max): int
    {
        try {
            $value = Config::cached($key);
        } catch (\Throwable) {
            return $default;
        }
        if ($value === null || !ctype_digit(trim((string)$value))) {
            return $default;
        }
        return max($min, min($max, (int)$value));
    }
}
