<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\Order;
use App\Model\User;
use Illuminate\Database\Eloquent\Builder;
use Kernel\Exception\JSONException;

/** Buyer-facing lookup: guest orders retain their query-password flow; member orders belong to the buyer. */
final class OrderAccess
{
    public static function visibleToBuyer(Builder $query, ?User $buyer): Builder
    {
        $owner = $query->getModel()->qualifyColumn('owner');
        $buyerId = (int)($buyer?->id ?? 0);
        return $query->where(static function (Builder $scope) use ($owner, $buyerId): void {
            $scope->where($owner, 0);
            if ($buyerId > 0) {
                $scope->orWhere($owner, $buyerId);
            }
        });
    }

    public static function assertCanRead(?Order $order, ?User $buyer): void
    {
        if ($order !== null && $order->owner !== null) {
            $owner = (int)$order->owner;
            if ($owner === 0 || ($owner > 0 && $buyer !== null && $owner === (int)$buyer->id)) {
                return;
            }
        }
        // An inaccessible order and a nonexistent order must produce the same response.
        throw new JSONException('未查询到相关信息');
    }
}
