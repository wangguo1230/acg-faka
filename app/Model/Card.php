<?php
declare(strict_types=1);

namespace App\Model;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $commodity_id
 * @property string $create_time
 * @property string $draft
 * @property int $id
 * @property int $order_id
 * @property int $owner
 * @property string $purchase_time
 * @property string $secret
 * @property array $sku
 * @property string $note
 * @property int $status
 * @property string $race
 * @property string $draft_premium
 * @property string $cost
 */
class Card extends Model
{
    /**
     * @var string
     */
    protected $table = "card";

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = ['commodity_id' => 'integer', 'id' => 'integer', 'order_id' => 'integer', 'owner' => 'integer', 'status' => 'integer', 'sku' => 'json'];


    public function owner(): ?HasOne
    {
        return $this->hasOne(User::class, "id", "owner");
    }

    public function commodity(): ?HasOne
    {
        return $this->hasOne(Commodity::class, "id", "commodity_id");
    }

    public function order(): ?HasOne
    {
        return $this->hasOne(Order::class, "id", "order_id");
    }

    /**
     * 这张卡是否属于订单所选的规格。与自动拉卡（Order::pullCardForLocal）同一口径：
     * 种类严格相等（空=未分类），订单选了的每个 SKU 卡上都必须同值。
     * 预选卡按客户端提交的 race/sku 计价、却交付 card_id 指向的卡，不比对就能用便宜规格的价买走贵规格的卡。
     */
    public function matchesSpec(?string $race, ?array $sku): bool
    {
        if ((string)$this->race !== (string)$race) {
            return false;
        }
        $cardSku = is_array($this->sku) ? $this->sku : [];
        foreach ($sku ?: [] as $k => $v) {
            if (!array_key_exists($k, $cardSku) || (string)$cardSku[$k] !== (string)$v) {
                return false;
            }
        }
        return true;
    }
}