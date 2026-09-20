<?php
declare(strict_types=1);

namespace App\Model;


use App\Util\Date;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use JetBrains\PhpStorm\NoReturn;
use Kernel\Exception\JSONException;

/**
 * @property float $amount
 * @property float $balance
 * @property string $create_time
 * @property int $id
 * @property string $log
 * @property int $owner
 * @property int $type
 * @property int $currency
 */
class Bill extends Model
{
    const TYPE_ADD = 1;
    const TYPE_SUB = 0;

    /**
     * @var string
     */
    protected $table = "bill";

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = ['amount' => 'float', 'balance' => 'float', 'id' => 'integer', 'owner' => 'integer', 'type' => 'integer', 'currency' => 'integer'];


    public function owner(): ?HasOne
    {
        return $this->hasOne(User::class, "id", "owner");
    }

    /**
     * @param User|int|string $user
     * @param float $amount
     * @param int $type
     * @param string $log
     * @param int $currency
     * @param bool $total
     * @throws JSONException
     */
    public static function create(User|int|string $user, float $amount, int $type, string $log, int $currency = 0, bool $total = true): void
    {
        if ($amount <= 0) {
            throw new JSONException("非法操作");
        }

        //金额精度总闸：余额/硬币列是 decimal(14,2)，但这里按 float 运算、由数据库隐式四舍五入回写。
        //传入亚分金额（如 0.005）时，扣款方向下舍回原值、收款方向上进位成 0.01，形成「凭空造币」的
        //四舍五入棘轮——转账自转、硬币兑现等路径皆可无限放大。这里拒绝任何超过两位小数的金额，
        //一处堵死全部调用点（转账/提现/下单/充值/分成/退款）。用 %.8F 固定记数避免科学计数法误判。
        $fixedAmount = sprintf('%.8F', $amount);
        if (bccomp($fixedAmount, bcadd($fixedAmount, '0', 2), 8) !== 0) {
            throw new JSONException("金额精度非法，最多支持两位小数");
        }

        if (is_int($user) || is_string($user)) {
            $user = User::query()->find($user);
            if (!$user) {
                throw new JSONException("用户不存在");
            }
        }

        if ($currency == 0) {
            $user->balance = $type == 0 ? $user->balance - $amount : $user->balance + $amount;

            if ($user->balance < 0) {
                throw new JSONException("余额不足");
            }

            if ($type == self::TYPE_ADD && $total) {
                $user->recharge = $user->recharge + $amount;
            }
        } else {
            $user->coin = $type == 0 ? $user->coin - $amount : $user->coin + $amount;

            if ($user->coin < 0) {
                throw new JSONException("硬币不足");
            }

            if ($type == self::TYPE_ADD && $total) {
                $user->total_coin = $user->total_coin + $amount;
            }
        }

        $user->save();
        $bill = new self();
        $bill->owner = $user->id;
        $bill->amount = $amount;
        $bill->currency = $currency;
        $bill->balance = $currency == 0 ? $user->balance : $user->coin;
        $bill->type = $type;
        $bill->log = $log;
        $bill->create_time = Date::current();
        $bill->save();
    }
}