<?php
declare(strict_types=1);

namespace App\Util;

use App\Consts\Pay;
use App\Model\Order as OrderModel;
use App\Model\UserRecharge;
use Illuminate\Database\Capsule\Manager as DB;
use App\Service\Bind\Order;

/** Resolve both callback URL formats without trusting an unverified order number. */
final class LegacyPayCallback
{
    public const TABLE = 'pay_legacy_callback';

    /** @return array{0:OrderModel|UserRecharge,1:array} */
    public static function resolve(string $parameter, array $map, bool $recharge = false): array
    {
        $legacy = !Order::isCallbackTradeNo($parameter);
        $handle = '';
        $tradeNo = $parameter;
        if ($legacy) {
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $parameter) || !PayConfig::isValid($parameter)) {
                self::reject($map);
            }
            $handle = $parameter;
            $callback = PayConfig::info($handle)['callback'] ?? null;
            // The compatibility route must authenticate the payload, including its order number.
            if (!is_array($callback) || empty($callback[Pay::IS_SIGN])) {
                self::reject($map);
            }
            $field = $callback[Pay::FIELD_ORDER_KEY] ?? null;
            $candidate = (is_string($field) || is_int($field)) ? ($map[$field] ?? null) : null;
            $tradeNo = is_scalar($candidate) ? (string)$candidate : '';
        }
        if (!Order::isCallbackTradeNo($tradeNo) || strlen($tradeNo) > 32) {
            self::reject($map);
        }

        $model = $recharge ? UserRecharge::class : OrderModel::class;
        $order = $model::with('pay')->where('trade_no', $tradeNo)->first();
        if (!$order || !$order->pay || ($legacy && (string)$order->pay->handle !== $handle)) {
            self::reject($map);
        }

        $snapshot = null;
        if ($order->gateway_amount === null && Schema::tableExists(self::TABLE)) {
            $snapshot = DB::table(self::TABLE)
                ->where('pay_id', (int)$order->pay_id)
                ->where('handle', (string)$order->pay->handle)
                ->first();
            $limit = $recharge ? 'recharge_max_id' : 'order_max_id';
            if ($snapshot && ((int)$order->id > (int)$snapshot->$limit || (int)$order->id <= 0)) {
                $snapshot = null;
            }
        }
        if ($legacy && $snapshot === null) {
            self::reject($map);
        }

        // Frozen separately from editable pay_config rows; changing an account cannot strand old payments.
        if ($snapshot !== null) {
            $values = json_decode((string)$snapshot->config, true);
            if (!is_array($values) || $values === []) {
                self::reject($map);
            }
            return [$order, $values];
        }
        return [$order, PayProfile::config($order->pay)];
    }

    private static function reject(array $map): void
    {
        Order::callbackFail('', 'legacy_route', Order::CALLBACK_REJECT, null, $map);
    }
}
