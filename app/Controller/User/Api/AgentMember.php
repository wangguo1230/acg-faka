<?php
declare(strict_types=1);

namespace App\Controller\User\Api;

use App\Controller\Base\API\User;
use App\Entity\Query\Get;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Service\Query;
use App\Util\Throttle;
use App\Util\TransferHoneypot;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Builder;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

#[Interceptor([Waf::class, UserSession::class], Interceptor::TYPE_API)]
class AgentMember extends User
{
    #[Inject]
    private Query $query;

    /**
     * @return array
     */
    public function data(): array
    {
        $map = $this->request->post();
        $get = new Get(\App\Model\User::class);
        $get->setOrderBy(...$this->query->getOrderBy($map, "id", "desc"));
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($map);
        //只允许按「已随列表返回」的非敏感列过滤。否则客户端可用 betweenStart-password / search-salt
        //之类，把 total 的 0/1 当布尔预言机，逐字符拖出下级用户的密码哈希与盐（离线爆破）。
        $get->setFilterColumns(["id", "pid", "username", "email", "phone", "qq", "create_time", "status", "balance", "recharge"]);
        $get->setColumn("id", "pid", "username", "email", "phone", "qq", "avatar", "create_time", "status", "balance", "recharge");
        $data = $this->query->get($get, function (Builder $builder) {
            return $builder->where("pid", $this->getUser()->id);
        });
        return $this->json(data: $data);
    }


    /**
     * @return array
     * @throws JSONException
     */
    public function transfer(): array
    {
        //收款方 ID 必须按整型解析：旧代码 $to 为字符串 + 松散比较，"5abc"==5 在 PHP8 为 false 可绕过
        //自转检查，而 MySQL 的 find("5abc") 又会截断成 5 命中本人，配合亚分金额形成自转刷币。
        $to = (int)$this->request->post("id");
        $amount = $this->request->post("amount", Filter::FLOAT);
        $userId = (int)$this->getUser()->id;

        if ($to <= 0) {
            throw new JSONException("请选择要转账的用户");
        }
        if ($amount <= 0) {
            throw new JSONException("转账金额必须大于0");
        }

        //限流覆盖蜜罐与正常两条路径，必须在分流之前。key 只按账号、**不含 IP**：含 IP 时
        //攻击者换一个 IP 就得到独立窗口，可秒级刷满蜜罐阈值并放大并发超发（与 Cash::submit 同口径）。
        if (Throttle::tooMany("transfer:" . $userId, 10, 60)) {
            throw new JSONException("转账操作过于频繁，请稍后再试");
        }

        //蜜罐：亚分金额是明确的攻击特征（前端是纯文本输入框、不做浮点运算，正常用户构造不出 0.005）。
        //只钓「自己转给自己」——它对应原始漏洞的自转刷币，且铸币方=收款方=被封方三者同一账号，
        //既无炮灰模型也无嫁祸风险。亚分转给他人一律拒绝、不铸币；关闭蜜罐时自转也照常拒绝。
        //口子只开在本方法，Bill::create 总闸不动——提现/下单/充值/分成一分钱都造不出来。
        if (TransferHoneypot::isSubCent($amount)) {
            if ($to !== $userId) {
                throw new JSONException("金额精度非法，最多支持两位小数");
            }
            return $this->honeypot($userId, (float)$amount);
        }

        //正常路径：自转无意义且曾被用于刷币，直接拒绝。
        if ($to === $userId) {
            throw new JSONException("非法操作");
        }

        DB::connection()->getPdo()->exec("set session transaction isolation level serializable");
        Db::transaction(function () use ($to, $amount, $userId) {
            //按 ID 升序对双方会员行 lockForUpdate，固定加锁顺序避免并发死锁；
            //复用锁内实例传给 Bill::create，避免其再次 find 读到未加锁的陈旧余额。
            $ids = $userId < $to ? [$userId, $to] : [$to, $userId];
            $locked = \App\Model\User::query()->whereIn("id", $ids)->lockForUpdate()->get()->keyBy("id");
            $from = $locked->get($userId);
            $recipient = $locked->get($to);
            if (!$from) {
                throw new JSONException("用户不存在");
            }
            if (!$recipient) {
                throw new JSONException("对方账号不存在");
            }
            if ((int)$recipient->status !== 1) {
                throw new JSONException("对方账号状态异常，无法转账");
            }
            \App\Model\Bill::create($from, $amount, \App\Model\Bill::TYPE_SUB, "转账给ID:{$to}", 0, false);
            \App\Model\Bill::create($recipient, $amount, \App\Model\Bill::TYPE_ADD, "来自ID:{$userId}的转账", 0, false);
        });

        return $this->json();
    }

    /**
     * 亚分「自转」的蜜罐分支（对应原始漏洞的自转刷币：自己给自己刷出 +0.01）。
     *
     * 关闭（默认）时行为与加固后完全一致：直接拒绝，一分钱都不产生。
     * 开启时复现四舍五入棘轮，让攻击者以为得手，并把尝试次数记在会员行上——
     * 计数**必须落库**（见 docker/migrate.php），不能用 Throttle：那是滑动窗口，过期即归零，
     * 攻击者等窗口过期就能重新拿满次数，封禁阈值永远触发不了；且其缓存异常时 fail-open。
     *
     * 铸币方=收款方=被封方三者同一账号，所以按后台配置的固定阈值直接封禁不会误伤/嫁祸他人。
     *
     * @throws JSONException
     */
    private function honeypot(int $userId, float $amount): array
    {
        $denied = "金额精度非法，最多支持两位小数";

        if (!TransferHoneypot::enabled()) {
            throw new JSONException($denied);
        }

        //计数与阈值判断在事务外：放进事务的话，后置的余额不足校验一旦回滚会把计数一并抹掉，
        //攻击者把余额控在不足线上就能无限试探而永不触及封禁阈值。
        //缺列/DB 异常一律 fail-closed（老站点未跑迁移又开了蜜罐时既不 500、也不铸币）。
        try {
            $count = TransferHoneypot::countAttempt($userId);
        } catch (\Throwable) {
            throw new JSONException($denied);
        }

        if ($count >= TransferHoneypot::limit()) {
            //达阈值：封号，且这一次不放行，避免"最后一次仍然得手"，也不占用配额。
            //封号后会话立即失效（UserSession 按 status==1 判定）。
            TransferHoneypot::ban($userId);
            //风控流水留证：封禁点。查一次当前余额仅作快照，写失败不影响封禁。
            $balance = (float)(\App\Model\User::query()->whereKey($userId)->value('balance') ?? 0);
            $this->honeypotBill($userId, 0.0, $balance, sprintf("风控蜜罐：亚分自转达阈值 %d 次，账号已封禁", TransferHoneypot::limit()));
            throw new JSONException("账号已被风控系统限制，请联系客服");
        }

        //全站总量闸：原子占用一格配额（读-判-写同锁内完成，无 TOCTOU）。占不到=已耗尽，
        //回到纯拒绝，把最坏情况钉死在可预期的数字上。放在铸币前占用，避免超发。
        if (!TransferHoneypot::reserveQuota()) {
            throw new JSONException($denied);
        }

        $before = 0.0;
        $after = 0.0;
        DB::connection()->getPdo()->exec("set session transaction isolation level serializable");
        Db::transaction(function () use ($userId, $amount, &$before, &$after): void {
            //自转铸币的正确复现：**单实例、扣款先落库、再回读被 decimal(14,2) 四舍五入后的值**，
            //然后加回。两步之间必须 refresh()——扣款的舍入要真正生效，收款才只在其基础上进位。
            //净收益 = 两次舍入误差之和 ∈ (-0.01, +0.01]，封顶 0.01（即原漏洞放行的那一分）。
            //
            //绝不能用「同一行的两个实例分别写」：那样第二次写覆盖第一次，扣款的损失消失，
            //净收益退化成 round(B+amount)-B ≈ amount——攻击者传大额亚分即可翻倍余额，是任意额度铸币。
            $u = \App\Model\User::query()->whereKey($userId)->lockForUpdate()->first();
            if (!$u) {
                throw new JSONException("用户不存在");
            }

            //余额闸。本分支绕过了 Bill::create，它那道 balance<0 守卫也一并失去了：
            //少了这一句，攻击者传 99999999.005 就能把自己扣成负数/UNSIGNED 越界。
            if ((float)$u->balance < $amount) {
                throw new JSONException("余额不足");
            }
            $before = (float)$u->balance;

            //第一步：扣款落库，由 decimal(14,2) 隐式四舍五入（亚分下舍，余额基本不变）。
            $u->balance = $u->balance - $amount;
            $u->save();
            //回读被四舍五入后的真实余额，再加回——收款方上进位，净增至多 0.01。
            $u->refresh();
            $u->balance = $u->balance + $amount;
            $u->save();
            $u->refresh();
            $after = (float)$u->balance;
        });

        //风控流水留证：命中一次记一条（时间/进位额/累计次数），出现在会员账单里可看全流程。
        //放在事务提交后：直接插 bill（不走 Bill::create，否则被精度闸拦且会二次加钱），
        //写失败不影响已成功的铸币，仅取证用。
        $shown = rtrim(rtrim(sprintf('%.8F', $amount), '0'), '.');
        $this->honeypotBill(
            $userId,
            $after - $before,
            $after,
            sprintf("风控蜜罐：亚分自转 %s，本次进位 +%s（第 %d 次尝试）", $shown, number_format($after - $before, 2), $count)
        );

        return $this->json();
    }

    /**
     * 直插一条余额账单记录，仅供转账蜜罐留证使用。
     *
     * 刻意**不走 Bill::create**：① 其精度闸会拒绝亚分金额；② 它会真的改动余额，
     * 而蜜罐的余额已由 honeypot() 自行处理，再走一遍会二次加钱。这里只写流水、不碰余额。
     * 字段与 Bill::create 保持一致（owner/amount/currency/balance/type/log/create_time）。
     * 写失败吞掉：账单仅用于事后取证，绝不能因为记不上流水而回滚风控或影响响应。
     */
    private function honeypotBill(int $userId, float $amount, float $balance, string $log): void
    {
        try {
            $bill = new \App\Model\Bill();
            $bill->owner = $userId;
            $bill->amount = $amount;
            $bill->currency = 0;
            $bill->balance = $balance;
            $bill->type = \App\Model\Bill::TYPE_ADD;
            $bill->log = $log;
            $bill->create_time = \App\Util\Date::current();
            $bill->save();
        } catch (\Throwable) {
        }
    }
}