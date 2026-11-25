<?php
/** Additional cases run only in the synthetic upgrade database. */
declare(strict_types=1);

use App\Model\Config;
use App\Util\Client;
use App\Util\Context;
use App\Util\Str;

// A valid API signature must not revive a disabled account or an empty API key.
$sharedAuth = new App\Interceptor\SharedValidation();
$sharedRequest = new ReflectionProperty($sharedAuth, 'request');
$apiCheck = static function (int $uid, string $key) use ($sharedAuth, $sharedRequest): void {
    $data = ['app_id' => (string)$uid];
    $data['sign'] = Str::generateSignature($data, $key);
    $_POST = $_REQUEST = $data;
    $_GET = $_COOKIE = [];
    Context::set(App\Consts\Shared::SESSION, null);
    $sharedRequest->setValue($sharedAuth, new Kernel\Context\Request());
    $sharedAuth->handle(Kernel\Annotation\Interceptor::TYPE_API);
};
$apiCheck(1100, 'fixture-1100');
$verify((int)Context::get(App\Consts\Shared::SESSION)->id === 1100, 'Active API account was rejected');
$reject(fn() => $apiCheck(1100, 'wrong-key'), 'Bad API signature accepted');
foreach ([0, 2] as $disabledStatus) {
    $db->table('user')->where('id', 1100)->update(['status' => $disabledStatus]);
    $reject(fn() => $apiCheck(1100, 'fixture-1100'), 'Disabled account authenticated with a valid API key');
}
$db->table('user')->where('id', 1100)->update(['status' => 1, 'app_key' => '']);
$reject(fn() => $apiCheck(1100, ''), 'An empty API key authenticated');
$db->table('user')->where('id', 1100)->update(['app_key' => 'fixture-1100']);

// The payment polling endpoint must enforce the same buyer scope as delivery.
$stateController = new App\Controller\User\Api\Order();
$poll = static function (?App\Model\User $buyer, string $trade) use ($stateController, $setRequest): array {
    Context::set(App\Consts\User::SESSION, $buyer);
    $setRequest($stateController, ['tradeNo' => $trade]);
    return $stateController->state($trade)['data'];
};
$reject(fn() => $poll(null, '889900000000000001'), 'Anonymous polling exposed a member order');
$reject(fn() => $poll($other, '889900000000000001'), 'Another member polled a private order');
$ownPoll = $poll($owner, '889900000000000001');
$verify((int)$ownPoll['status'] === 1 && !isset($ownPoll['owner']), 'Buyer polling failed or exposed internal ownership fields');
$verify((int)$poll(null, '889900000000000003')['status'] === 1, 'Historical guest payment polling stopped working');
$rechargeTrade = '889900000000000010';
$db->table('user_recharge')->insert(['trade_no' => $rechargeTrade, 'user_id' => 1100, 'amount' => 10, 'pay_id' => 2,
    'status' => 0, 'create_time' => date('Y-m-d H:i:s'), 'create_ip' => '127.0.0.1']);
$reject(fn() => $poll(null, $rechargeTrade), 'Anonymous polling exposed a recharge');
$reject(fn() => $poll($other, $rechargeTrade), 'Another member polled a private recharge');
$verify((int)$poll($owner, $rechargeTrade)['status'] === 0, 'Recharge owner could not poll payment status');

// Prove the migrated TOTP column stores a binding, revokes existing sessions,
// and requires a second factor at the actual SSO entrypoint.
$templateAdmin = (array)$db->table('manage')->where('id', 1)->first();
foreach ([1200 => 'mfa-admin@example.test', 1201 => 'budget-admin@example.test', 1202 => 'other-admin@example.test'] as $id => $email) {
    $row = $templateAdmin;
    $row['id'] = $id;
    $row['email'] = $email;
    $row['password'] = Str::hashPassword($password);
    $row['status'] = 1;
    $row['type'] = 0;
    $row['google_secret'] = null;
    $db->table('manage')->insert($row);
}
$manage = App\Model\Manage::query()->findOrFail(1200);
$oldSession = App\Service\ManageSessionManager::issue($manage, time() + 3600);
Context::set(App\Consts\Manage::SESSION, $manage);
$settingsController = new App\Controller\Admin\Api\Manage();
$totpSecret = App\Util\Totp::generateSecret();
$calculate = new ReflectionMethod(App\Util\Totp::class, 'calculate');
$code = (string)$calculate->invoke(null, $totpSecret, (int)floor(time() / 30));
$setRequest($settingsController, ['secret' => $totpSecret, 'code' => 'invalid']);
$reject(fn() => $settingsController->googleBind(), 'Invalid TOTP binding accepted');
$verify($db->table('manage')->where('id', 1200)->value('google_secret') === null, 'Rejected binding saved a secret');
$setRequest($settingsController, ['secret' => $totpSecret, 'code' => $code]);
$verify($settingsController->googleBind()['code'] === 200, 'Admin TOTP binding failed after migration');
$verify($db->table('manage')->where('id', 1200)->value('google_secret') === $totpSecret, 'Admin TOTP secret was not persisted');
$verify(App\Service\ManageSessionManager::authenticate($oldSession['cookie'], false) === null, 'Binding TOTP did not revoke the previous session');
$adminSSO = new App\Service\Bind\ManageSSO();
try {
    $adminSSO->login($manage->email, $password);
    throw new RuntimeException('Admin password bypassed the newly bound second factor');
} catch (Kernel\Exception\JSONException $e) {
    $verify($e->getCode() === App\Service\Bind\ManageSSO::CODE_NEED_TOTP, 'Admin did not receive the TOTP challenge');
}
$reject(fn() => $adminSSO->login($manage->email, $password, false, 'invalid'), 'Invalid admin login TOTP accepted');
$adminSSO->login($manage->email, $password, false, $code);
$verify($db->table('manage_session')->where('manage_id', 1200)->whereNull('revoked_time')->exists(), 'Valid TOTP did not produce an admin session');

// Distributed attempts and database-equivalent email spellings share one budget.
Client::setClientMode(0);
Config::put('admin_login_verification', '0');
$adminAuth = new App\Controller\Admin\Api\Authentication();
(new ReflectionProperty($adminAuth, 'sso'))->setValue($adminAuth, $adminSSO);
$adminLogin = static function (string $email, string $pw, string $ip) use ($adminAuth, $setRequest): array {
    $_SERVER['REMOTE_ADDR'] = $ip;
    $setRequest($adminAuth, ['username' => $email, 'password' => $pw]);
    return $adminAuth->login($email, $pw);
};
for ($i = 1; $i <= 30; $i++) {
    $reject(fn() => $adminLogin('budget-admin@example.test', 'wrong-password', '2001:db8:1::' . $i), 'Wrong admin password accepted');
}
$verify(str_contains($reject(fn() => $adminLogin('búdget-admin@example.test', $password, '2001:db8:2::1'), 'Email alias bypassed the admin account budget'), '频繁'), 'Admin account budget was not shared across IPs/aliases');
for ($i = 0; $i < 7; $i++) {
    $reject(fn() => $adminLogin('unknown-' . $i . '@example.test', 'wrong-password', '192.0.2.201'), 'Unknown admin accepted');
}
$verify($adminLogin('other-admin@example.test', $password, '192.0.2.201')['code'] === 200, 'Valid admin login failed before IP budget was exhausted');
for ($i = 7; $i < 9; $i++) {
    $reject(fn() => $adminLogin('unknown-' . $i . '@example.test', 'wrong-password', '192.0.2.201'), 'Unknown admin accepted');
}
$verify(str_contains($reject(fn() => $adminLogin('other-admin@example.test', $password, '192.0.2.201'), 'Successful admin login reset the IP budget'), '频繁'), 'Admin IP budget must survive successful login');
$verify($adminLogin('other-admin@example.test', $password, '192.0.2.202')['code'] === 200, 'An unrelated admin IP was blocked');
