<?php
declare(strict_types=1);

namespace App\Controller\User\Api;

use App\Controller\Base\API\User;
use App\Entity\Query\Get;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Service\Query;
use App\Util\Client;
use App\Util\Throttle;
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
        if ($to === $userId) {
            throw new JSONException("非法操作");
        }
        if ($amount <= 0) {
            throw new JSONException("转账金额必须大于0");
        }
        //金额精度（拒绝亚分金额）由 Bill::create 入口统一把关，全部余额入口共用同一道闸。

        //限流：正常用户不会高频转账，挡住脚本化刷单（与 Cash::submit 同口径）。
        if (Throttle::tooMany("transfer:" . $userId . ":" . Client::getAddress(), 10, 60)) {
            throw new JSONException("转账操作过于频繁，请稍后再试");
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
}