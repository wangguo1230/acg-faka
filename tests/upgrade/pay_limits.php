<?php
/** Run inside the isolated upgrade database via scenarios.php pay_limits. */
declare(strict_types=1);

use App\Model\Pay;
use App\Util\PayLimit;

check($db->getSchemaBuilder()->hasColumns('pay', ['min_amount', 'max_amount']), 'Startup did not migrate payment limits');
$payment = Pay::query()->findOrFail(2);
$originalPay = $payment->getAttributes();
$originalCommodity = (array)$db->table('commodity')->where('id', 1)->first();

try {
    $payment->forceFill(['min_amount' => '10.00', 'max_amount' => '100.00', 'cost' => 0, 'cost_type' => 0]);
    foreach (['10.00', '50.00', '100.00'] as $amount) {
        check(PayLimit::violation($payment, $amount) === null, "Inclusive boundary {$amount} rejected");
    }
    foreach (['9.99', '100.01'] as $amount) {
        rejected(fn() => PayLimit::assert($payment, $amount), "Out-of-range amount {$amount} accepted");
    }
    $payment->min_amount = 0;
    check(PayLimit::violation($payment, '0.01') === null, 'Zero minimum should be unlimited');
    $payment->max_amount = 0;
    check(PayLimit::violation($payment, '99999999.99') === null, 'Zero maximum should be unlimited');
    $payment->min_amount = 10;
    check(PayLimit::violation($payment, '0.00') === null, 'Free purchase should bypass gateway limits');
    $payment->handle = '#system';
    check(PayLimit::violation($payment, '1.00') === null, 'Balance payment should bypass gateway limits');
    $payment->handle = 'Epay';
    $payment->cost = '1.005';
    check(PayLimit::payable($payment, '10.00') === '11.00', 'Fixed fee must truncate to cents');
    $payment->cost_type = 1;
    $payment->cost = '0.015';
    check(PayLimit::payable($payment, '100.01') === '101.51', 'Percentage fee must truncate to cents');
    $legacy = PayLimit::present([['id' => 2]])[0];
    check($legacy['min_amount'] === 0.0 && $legacy['max_amount'] === 0.0, 'Legacy payment list lost unlimited defaults');

    // Partial admin updates must also respect the other, unchanged boundary.
    $validator = new ReflectionMethod(App\Controller\Admin\Api\Pay::class, 'paymentSaveMap');
    $validator->setAccessible(true);
    $controller = new App\Controller\Admin\Api\Pay();
    $payment->forceFill(['min_amount' => '10.00', 'max_amount' => '100.00', 'cost' => 0, 'cost_type' => 0]);
    $validate = fn(array $fields) => $validator->invoke($controller, $fields, $payment);
    foreach ([['min_amount' => '-1'], ['max_amount' => '1.001'], ['min_amount' => '1e2'],
                 ['max_amount' => '100000000'], ['min_amount' => []], ['min_amount' => '101'], ['max_amount' => '9.99']] as $fields) {
        rejected(fn() => $validate($fields), 'Invalid admin limit accepted: ' . json_encode($fields));
    }
    check($validate(['min_amount' => '', 'max_amount' => '']) === ['min_amount' => '0', 'max_amount' => '0'], 'Blank limits did not clear restrictions');
    check($validate(['min_amount' => '100'])['min_amount'] === '100', 'Equal bounds should be valid');

    $db->table('commodity')->where('id', 1)->update(['price' => 10, 'user_price' => 10, 'owner' => 0,
        'delivery_way' => 0, 'draft_status' => 0, 'contact_type' => 0, 'password_status' => 0, 'config' => '', 'level_disable' => 0]);
    $db->table('pay')->where('id', 2)->update(['commodity' => 1, 'recharge' => 1, 'cost' => 1,
        'cost_type' => 0, 'min_amount' => 11, 'max_amount' => 11]);
    $base = ['item_id' => 1, 'device' => 0, 'num' => 1, 'pay_id' => 2, 'contact' => 'test-buyer',
        'password' => '', 'card_id' => 0, 'coupon' => '', 'race' => '', 'request_no' => '', 'sku' => []];
    $orders = orderService();
    $recharges = rechargeService();
    $user = App\Model\User::query()->findOrFail(1000);
    $_POST = ['pay_id' => 2, 'amount' => 10];
    $order = $orders->trade(null, null, $base);
    check((float)$order['amount'] === 11.0, 'Purchase did not include fixed fee at the boundary');
    $topup = $recharges->trade($user);
    check((float)$topup['amount'] === 11.0, 'Recharge did not include fixed fee at the boundary');

    foreach ([['min_amount' => 0, 'max_amount' => '10.99'], ['min_amount' => '11.01', 'max_amount' => 0]] as $limits) {
        $db->table('pay')->where('id', 2)->update($limits);
        $beforeOrders = $db->table('order')->count();
        $beforeTopups = $db->table('user_recharge')->count();
        $gatewayBefore = file_get_contents(BASE_PATH . '/runtime/test-gateway.json');
        rejected(fn() => $orders->trade(null, null, $base), 'Direct purchase API bypassed payment limits');
        rejected(fn() => $recharges->trade($user), 'Direct recharge API bypassed payment limits');
        check($db->table('order')->count() === $beforeOrders, 'Rejected purchase left an order');
        check($db->table('user_recharge')->count() === $beforeTopups, 'Rejected recharge left an order');
        check(file_get_contents(BASE_PATH . '/runtime/test-gateway.json') === $gatewayBefore, 'Rejected trade reached the gateway');
    }

    $db->table('pay')->where('id', 2)->update(['cost' => '0.015', 'cost_type' => 1, 'min_amount' => '10.15', 'max_amount' => '10.15']);
    check((float)$orders->trade(null, null, $base)['amount'] === 10.15, 'Purchase percentage fee boundary rejected');
    check((float)$recharges->trade($user)['amount'] === 10.15, 'Recharge percentage fee boundary rejected');

    // Lowering limits must not strand payments already submitted to the gateway.
    $gateway = json_decode(file_get_contents(BASE_PATH . '/runtime/test-gateway.json'), true);
    $db->table('pay')->where('id', 2)->update(['max_amount' => '0.01', 'min_amount' => 0]);
    check($recharges->callback($gateway['trade'], payload($gateway['trade'], (string)$gateway['amount'], $gateway['config']['key'])) === 'success', 'New limit blocked an existing payment callback');
} finally {
    $db->table('pay')->where('id', 2)->update($originalPay);
    $db->table('commodity')->where('id', 1)->update($originalCommodity);
}
