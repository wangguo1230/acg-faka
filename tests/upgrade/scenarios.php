<?php
declare(strict_types=1);

const BASE_PATH = '/var/www/html';
const DEBUG = false;
const APP_VERSION = '3.7.6';
require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/kernel/Helper.php';
date_default_timezone_set('Asia/Shanghai');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
$_SERVER += ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'test.invalid', 'HTTP_USER_AGENT' => 'Upgrade test', 'REQUEST_METHOD' => 'POST'];
$capsule = new Illuminate\Database\Capsule\Manager();
$capsule->addConnection(config('database'));
$capsule->setAsGlobal();
$capsule->bootEloquent();
$db = $capsule->getConnection();
$action = $argv[1];

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function configFile(array $values): void {
    file_put_contents(BASE_PATH . '/app/Pay/Epay/Config/Config.php', '<?php return ' . var_export($values, true) . ';');
}
function payload(string $trade, string $amount = '10.00', string $key = 'old-test-key', array $extra = []): array {
    $data = ['out_trade_no' => $trade, 'money' => $amount, 'trade_status' => 'TRADE_SUCCESS'] + $extra;
    ksort($data);
    $data['sign'] = hash_hmac('sha256', http_build_query($data), $key);
    return $data;
}
function rejected(callable $operation, string $message): void {
    try { $operation(); } catch (Kernel\Exception\JSONException $e) { return; }
    throw new RuntimeException($message);
}
function orderService(): App\Service\Bind\Order { return new App\Service\Bind\Order(); }
function rechargeService(): App\Service\Bind\Recharge {
    $service = new App\Service\Bind\Recharge();
    $property = new ReflectionProperty($service, 'order');
    $property->setAccessible(true);
    $property->setValue($service, orderService());
    return $service;
}
function addOrder(string $trade, ?float $gateway = null): int {
    global $db;
    $data = ['trade_no' => $trade, 'amount' => 10, 'commodity_id' => 1, 'card_num' => 1, 'pay_id' => 2,
        'create_time' => date('Y-m-d H:i:s'), 'create_ip' => '127.0.0.1', 'create_device' => 0,
        'contact' => 'test', 'widget' => '[]'];
    if ($db->getSchemaBuilder()->hasColumn('order', 'gateway_amount')) $data['gateway_amount'] = $gateway;
    return (int)$db->table('order')->insertGetId($data);
}

if ($action === 'seed') {
    configFile(['pid' => 'old-merchant', 'key' => 'old-test-key', 'top' => 0]);
    $db->table('user')->insert(['id' => 1000, 'username' => 'upgrade-user', 'password' => str_repeat('a', 32),
        'salt' => 'test', 'app_key' => 'test', 'create_time' => date('Y-m-d H:i:s'), 'status' => 1]);
    $db->table('commodity')->where('id', 1)->update(['delivery_way' => 0, 'send_email' => 0]);
    for ($i = 0; $i < 8; $i++) {
        $db->table('card')->insert(['commodity_id' => 1, 'secret' => 'test-card-' . $i, 'create_time' => date('Y-m-d H:i:s')]);
    }
    addOrder('2026091900000000001');
    addOrder('2026091900000000002');
    $db->table('user_recharge')->insert(['trade_no' => '2026091900000000003', 'user_id' => 1000,
        'amount' => 11, 'pay_cost' => 1, 'pay_id' => 2, 'status' => 0,
        'create_time' => date('Y-m-d H:i:s'), 'create_ip' => '127.0.0.1']);
    $inactive = (array)$db->table('pay')->where('id', 2)->first();
    unset($inactive['id']);
    $inactive['handle'] = 'UnusedPay';
    $inactive['commodity'] = $inactive['recharge'] = 0;
    $db->table('pay')->insert($inactive);
} elseif ($action === 'partial') {
    $db->getSchemaBuilder()->table('pay', static fn($t) => $t->unsignedInteger('pay_config_id')->default(0));
    $db->getSchemaBuilder()->create('pay_config', static function ($t) {
        $t->increments('id'); $t->string('handle', 64); $t->string('name', 32);
        $t->mediumText('config')->nullable(); $t->unsignedSmallInteger('sort')->default(0);
        $t->dateTime('create_time'); $t->dateTime('update_time')->nullable();
        $t->unique(['handle', 'name']);
    });
    $id = $db->table('pay_config')->insertGetId(['handle' => 'Epay', 'name' => '默认配置', 'config' => '[]', 'create_time' => date('Y-m-d H:i:s')]);
    $db->table('pay')->where('id', 2)->update(['pay_config_id' => $id]);
} elseif ($action === 'assert_migration') {
    check($db->table('pay_config')->count() === 1, 'Repeated migration created duplicate profiles');
    $pay = App\Model\Pay::query()->find(2);
    check(App\Util\PayProfile::config($pay)['key'] === 'old-test-key', 'Legacy merchant config was not imported');
    check($db->table('pay_legacy_callback')->where('pay_id', 2)->exists(), 'Old callback snapshot missing');
    check((int)$db->table('pay')->where('handle', 'UnusedPay')->value('pay_config_id') === 0, 'Inactive payment changed');
    check(App\Util\Csp::mode() === 'report', 'Upgrade unexpectedly enabled enforcing CSP');
    check($db->table('docker_migration')->count() === 1, 'Migration completion missing');
    check((require BASE_PATH . '/app/Pay/Epay/Config/Config.php')['key'] === 'old-test-key', 'Legacy file overwritten');
} elseif ($action === 'callbacks') {
    $service = orderService();
    rejected(fn() => $service->callback('Epay', payload('2026091900000000001', '10.00', 'wrong')), 'Bad signature accepted');
    rejected(fn() => $service->callback('Epay', payload('2026091900000000001', '9.00')), 'Bad amount accepted');
    rejected(fn() => $service->callback('Epay', payload('2026091900000000001', '10.00', 'old-test-key', ['verified_order' => '2026091900000000002'])), 'Verified order mismatch accepted');
    rejected(fn() => $service->callback('../Epay', payload('2026091900000000001')), 'Invalid handle accepted');
    $source = (array)$db->table('pay')->where('id', 2)->first();
    unset($source['id']); $source['handle'] = 'OtherPay';
    $otherPayId = $db->table('pay')->insertGetId($source);
    $db->table('order')->where('id', 1)->update(['pay_id' => $otherPayId]);
    rejected(fn() => $service->callback('Epay', payload('2026091900000000001')), 'Cross-plugin order accepted');
    $db->table('order')->where('id', 1)->update(['pay_id' => 2]);
    $db->table('pay')->where('id', $otherPayId)->delete();
    check($db->table('card')->where('status', 1)->count() === 0, 'Rejected callbacks delivered a card');

    // The live account and the file can change without breaking an old callback.
    $db->table('pay_config')->update(['config' => json_encode(['pid' => 'new-merchant', 'key' => 'new-test-key'])]);
    configFile(['top' => 1]);
    rejected(fn() => $service->callback('Epay', payload('2026091900000000001', '10.00', 'new-test-key')), 'Wrong merchant authenticated old order');
    check($service->callback('Epay', payload('2026091900000000001')) === 'success', 'Legacy callback failed');
    check($service->callback('Epay', payload('2026091900000000001')) === 'success', 'Duplicate callback not acknowledged');
    rejected(fn() => $service->callback('Epay', payload('2026091900000000001', '9.00')), 'Bad duplicate amount accepted');
    check($db->table('card')->where('status', 1)->count() === 1, 'Duplicate callback delivered twice');

    $recharge = rechargeService();
    rejected(fn() => $recharge->callback('Epay', payload('2026091900000000003', '11.00', 'wrong')), 'Bad recharge signature accepted');
    check($recharge->callback('Epay', payload('2026091900000000003', '11.00')) === 'success', 'Legacy recharge failed');
    check($recharge->callback('Epay', payload('2026091900000000003', '11.00')) === 'success', 'Recharge retry failed');
    check((float)$db->table('user')->where('id', 1000)->value('balance') === 10.0, 'Recharge fee credited or credited twice');
    check($db->table('bill')->where('owner', 1000)->count() === 1, 'Duplicate recharge bill');

    addOrder('2026091900000000004', 10.0);
    rejected(fn() => $service->callback('Epay', payload('2026091900000000004')), 'Legacy route accepted new order');
    check($service->callback('2026091900000000004', payload('2026091900000000004', '10.00', 'new-test-key')) === 'success', 'New callback failed');
    addOrder('2026091900000000005');
    rejected(fn() => $service->callback('Epay', payload('2026091900000000005')), 'Legacy snapshot range was extended');
} elseif ($action === 'notify') {
    check(orderService()->callback('Epay', payload('2026091900000000002')) === 'success', 'Concurrent notification failed');
} elseif ($action === 'assert_concurrent') {
    check($db->table('card')->where('order_id', 2)->where('status', 1)->count() === 1, 'Concurrent callback delivered twice');
} elseif ($action === 'multi') {
    $db->table('pay_config')->update(['config' => '[]']);
    $id = $db->table('pay_config')->insertGetId(['handle' => 'Epay', 'name' => 'Active merchant',
        'config' => json_encode(['pid' => 'second', 'key' => 'second-key']), 'sort' => 1, 'create_time' => date('Y-m-d H:i:s')]);
    $db->table('pay')->where('id', 2)->update(['pay_config_id' => $id]);
    configFile(['top' => 1]);
} elseif ($action === 'assert_multi') {
    check($db->table('pay_config')->count() === 2, 'Migration changed configuration count');
    check((int)$db->table('pay')->where('id', 2)->value('pay_config_id') === 2, 'Migration rebound the active account');
    check($db->table('pay_config')->where('id', 1)->value('config') === '[]', 'Unused config was modified');
    check(App\Util\PayProfile::config(App\Model\Pay::query()->find(2))['key'] === 'second-key', 'Active profile changed');
    check(json_decode($db->table('pay_legacy_callback')->where('pay_id', 2)->value('config'), true)['key'] === 'old-test-key', 'Historical credentials changed');
} elseif ($action === 'invalid_binding') {
    $db->table('pay')->where('id', 2)->update(['pay_config_id' => 999]);
} elseif ($action === 'assert_invalid_binding') {
    check((int)$db->table('pay')->where('id', 2)->value('pay_config_id') === 999, 'Invalid binding silently replaced');
} elseif ($action === 'broken') {
    configFile(['top' => 1]);
} elseif ($action === 'repair') {
    configFile(['pid' => 'old-merchant', 'key' => 'old-test-key', 'top' => 0]);
} elseif ($action === 'assert_preflight') {
    check(!$db->getSchemaBuilder()->hasTable('pay_config'), 'Read-only preflight wrote schema');
    check(!$db->getSchemaBuilder()->hasColumn('order', 'gateway_amount'), 'Read-only preflight added a column');
} elseif ($action === 'empty_history') {
    $db->table('order')->delete();
    $db->table('user_recharge')->delete();
} elseif ($action === 'assert_empty_history') {
    $row = $db->table('pay_legacy_callback')->where('pay_id', 2)->first();
    check($row && (int)$row->order_max_id === 0 && (int)$row->recharge_max_id === 0, 'Empty legacy window was not frozen');
    $trade = '2026091900000000009';
    if (!$db->table('order')->where('trade_no', $trade)->exists()) addOrder($trade);
    rejected(fn() => orderService()->callback('Epay', payload($trade)), 'Restart extended zero legacy window');
} elseif ($action === 'before_upgrade') {
    $service = orderService();
    check($service->callback('Epay', payload('2026091900000000001')) === 'success', 'Rollback failed before migration');
    check($service->callback('Epay', payload('2026091900000000001')) === 'success', 'Rollback duplicate failed before migration');
    rejected(fn() => $service->callback('Epay', payload('2026091900000000002', '10.00', 'old-test-key', ['verified_order' => '2026091900000000001'])), 'Unsigned order routing accepted before migration');
    check(rechargeService()->callback('Epay', payload('2026091900000000003', '11.00')) === 'success', 'Rollback recharge failed before migration');
} elseif ($action === 'trades') {
    $hasProfiles = $db->getSchemaBuilder()->hasTable('pay_config');
    $key = $hasProfiles
        ? App\Util\PayProfile::config(App\Model\Pay::query()->find(2))['key']
        : (require BASE_PATH . '/app/Pay/Epay/Config/Config.php')['key'];
    $db->table('config')->updateOrInsert(['key' => 'callback_domain'], ['value' => 'https://notify.invalid']);
    @unlink(BASE_PATH . '/runtime/config');
    $db->table('pay')->where('id', 2)->update(['recharge' => 1, 'commodity' => 1, 'cost_type' => 0, 'cost' => 1]);
    $db->table('commodity')->where('id', 1)->update(['price' => 10, 'contact_type' => 0, 'password_status' => 0]);
    $orders = orderService();
    $order = $orders->trade(null, null, ['item_id' => 1, 'device' => 0, 'num' => 1, 'pay_id' => 2,
        'contact' => 'test-buyer', 'password' => '', 'card_id' => 0, 'coupon' => '', 'race' => '', 'request_no' => '', 'sku' => []]);
    $gateway = json_decode(file_get_contents(BASE_PATH . '/runtime/test-gateway.json'), true);
    $route = $hasProfiles ? $order['tradeNo'] : 'Epay';
    check($gateway['config']['key'] === $key, 'New purchase used the wrong account');
    check($gateway['callback'] === 'https://notify.invalid/user/api/order/callback.' . $route, 'Purchase callback route incorrect');
    check(str_starts_with($gateway['return'], 'http://test.invalid/'), 'Purchase returned to notification domain');
    check($orders->callback($route, payload($order['tradeNo'], (string)$gateway['amount'], $key)) === 'success', 'New purchase payment failed');
    check($db->table('order')->where('trade_no', $order['tradeNo'])->value('delivery_status') == 1, 'New purchase not delivered');
    $_POST = ['pay_id' => 2, 'amount' => 10];
    $before = (float)$db->table('user')->where('id', 1000)->value('balance');
    $recharges = rechargeService();
    $order = $recharges->trade(App\Model\User::query()->find(1000));
    $gateway = json_decode(file_get_contents(BASE_PATH . '/runtime/test-gateway.json'), true);
    $route = $hasProfiles ? $order['tradeNo'] : 'Epay';
    check($gateway['config']['key'] === $key, 'New recharge used the wrong account');
    check($gateway['callback'] === 'https://notify.invalid/user/api/rechargeNotification/callback.' . $route, 'Recharge callback route incorrect');
    check($gateway['return'] === 'http://test.invalid/user/recharge/index', 'Recharge returned to notification domain');
    check($recharges->callback($route, payload($order['tradeNo'], (string)$gateway['amount'], $key)) === 'success', 'New recharge payment failed');
    check($recharges->callback($route, payload($order['tradeNo'], (string)$gateway['amount'], $key)) === 'success', 'New recharge duplicate failed');
    check((float)$db->table('user')->where('id', 1000)->value('balance') === $before + 10, 'New recharge credited fee or duplicated');
} elseif ($action === 'rollback_config') {
    $controller = new App\Controller\Admin\Api\Pay();
    $request = new Kernel\Context\Request();
    rejected(fn() => $controller->setPluginConfig($request), 'Rollback allowed editing stale file credentials');
    rejected(fn() => $controller->save($request), 'Rollback allowed rebinding payment');
    rejected(fn() => $controller->del(), 'Rollback allowed deleting payment');
    $db->table('config')->where('key', 'currency_code')->update(['value' => 'USD']);
    @unlink(BASE_PATH . '/runtime/config');
    rejected(fn() => orderService()->trade(null, null, []), 'Rollback accepted unsupported currency purchase');
    rejected(fn() => rechargeService()->trade(App\Model\User::query()->find(1000)), 'Rollback accepted unsupported currency recharge');
    $db->table('config')->where('key', 'currency_code')->update(['value' => 'CNY']);
    @unlink(BASE_PATH . '/runtime/config');
} elseif ($action === 'smoke') {
    configFile(['pid' => 'old-merchant', 'key' => 'old-test-key', 'top' => 0]);
    (new App\Service\Bind\Pay())->savePluginConfig('Epay', ['top' => 1]);
    $saved = require BASE_PATH . '/app/Pay/Epay/Config/Config.php';
    check($saved['key'] === 'old-test-key' && $saved['top'] === 1, 'Pinning plugin lost legacy credentials');
    $manage = App\Model\Manage::query()->find(1);
    $issued = App\Service\ManageSessionManager::issue($manage, time() + 3600);
    check(App\Service\ManageSessionManager::authenticate($issued['cookie']) !== null, 'Admin authentication failed');
    App\Service\ManageSessionManager::revokeEncodedToken($issued['cookie']);
    check(App\Service\ManageSessionManager::authenticate($issued['cookie']) === null, 'Admin revocation failed');
    App\Util\Context::set(App\Consts\Manage::SESSION, $manage);
    $_POST = ['ids' => ['6', '8'], 'equal-secret' => 'test-card-6', 'export_status' => '1'];
    $_REQUEST = $_POST;
    $controller = new App\Controller\Admin\Api\Card();
    $property = new ReflectionProperty(App\Controller\Base\Manage::class, 'request');
    $property->setAccessible(true);
    $property->setValue($controller, new Kernel\Context\Request());
    check($controller->exportImpact()['data']['count'] === 2, 'Selected export preview failed');
    check($controller->export() === 'test-card-5' . PHP_EOL . 'test-card-7' . PHP_EOL, 'Selected export changed scope');
    check((int)$db->table('card')->where('id', 7)->value('status') === 0, 'Unselected card changed');
} else {
    throw new RuntimeException('Unknown scenario: ' . $action);
}
echo 'PASS ', $action, PHP_EOL;
