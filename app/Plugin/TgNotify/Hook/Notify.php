<?php
declare(strict_types=1);

namespace App\Plugin\TgNotify\Hook;

use App\Consts\Hook as HookPoint;
use App\Model\Commodity;
use App\Model\Order;
use App\Util\Http;
use Kernel\Annotation\Hook;

class Notify
{
    /**
     * 客户付款成功后触发（余额支付、第三方回调均会经过此点）。
     * 传参：Commodity $commodity, Order $order, Pay|null $pay
     */
    #[Hook(point: HookPoint::USER_API_ORDER_PAY_AFTER)]
    public function onPayAfter(Commodity $commodity, Order $order, $pay = null): void
    {
        // 通知逻辑全程兜底，任何异常都不得影响发货主流程
        try {
            $cfg = self::config();

            if (!empty($cfg['notify_paid'])) {
                $this->send($cfg, $this->buildPaidText($commodity, $order, $pay));
            }

            // 手动/插件发货商品（delivery_way=1 且非远程对接）付款后仍处于待发货状态，提醒去发货
            if (!empty($cfg['notify_pending'])
                && (int)$commodity->delivery_way === 1
                && empty($commodity->shared_id)
                && (int)$order->delivery_status === 0) {
                $this->send($cfg, $this->buildPendingText($commodity, $order));
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * 管理员后台手动发货成功后触发。
     * 传参：Order $order
     */
    #[Hook(point: HookPoint::ADMIN_API_ORDER_MANUAL_DELIVERY)]
    public function onManualDelivery(Order $order): void
    {
        try {
            $cfg = self::config();
            if (empty($cfg['notify_manual'])) {
                return;
            }
            $this->send($cfg, $this->buildManualText($order));
        } catch (\Throwable $e) {
        }
    }

    /**
     * 读取插件最新配置。
     * @return array
     */
    private static function config(): array
    {
        return (array)getPluginConfig("TgNotify");
    }

    private function buildPaidText(Commodity $commodity, Order $order, $pay): string
    {
        $payName = $pay?->name ?? "余额/其它";
        return "🎉 新订单付款成功\n"
            . "商品：" . strip_tags((string)$commodity->name) . "\n"
            . "单号：" . $order->trade_no . "\n"
            . "数量：" . $order->card_num . "\n"
            . "金额：￥" . $order->amount . "\n"
            . "支付方式：" . $payName . "\n"
            . "联系方式：" . $order->contact . "\n"
            . "时间：" . $order->pay_time;
    }

    private function buildPendingText(Commodity $commodity, Order $order): string
    {
        return "🟡 有新订单待人工发货\n"
            . "商品：" . strip_tags((string)$commodity->name) . "\n"
            . "单号：" . $order->trade_no . "\n"
            . "数量：" . $order->card_num . "\n"
            . "联系方式：" . $order->contact . "\n"
            . "请尽快到后台「订单管理」发货。";
    }

    private function buildManualText(Order $order): string
    {
        $name = strip_tags((string)($order->commodity?->name ?? "未知商品"));
        return "📦 订单已人工发货\n"
            . "商品：" . $name . "\n"
            . "单号：" . $order->trade_no . "\n"
            . "数量：" . $order->card_num . "\n"
            . "联系方式：" . $order->contact . "\n"
            . "发货内容：" . mb_substr(strip_tags((string)$order->secret), 0, 500);
    }

    /**
     * 发送 Telegram 消息。chat_id 支持英文逗号分隔多个；失败静默，不影响发货主流程。
     * @param array $cfg
     * @param string $text
     */
    private function send(array $cfg, string $text): void
    {
        try {
            $token = trim((string)($cfg['bot_token'] ?? ''));
            $chatIds = trim((string)($cfg['chat_id'] ?? ''));
            if ($token === '' || $chatIds === '') {
                return;
            }
            $base = rtrim(trim((string)($cfg['api_base'] ?? '')), '/');
            if ($base === '') {
                $base = 'https://api.telegram.org';
            }

            foreach (explode(',', $chatIds) as $chatId) {
                $chatId = trim($chatId);
                if ($chatId === '') {
                    continue;
                }
                Http::make()->post("{$base}/bot{$token}/sendMessage", [
                    'form_params' => [
                        'chat_id' => $chatId,
                        'text' => $text,
                        'disable_web_page_preview' => true,
                    ],
                    'timeout' => 10,
                ]);
            }
        } catch (\Throwable $e) {
            // 通知失败不影响主流程
        }
    }
}
