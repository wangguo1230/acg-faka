<?php
/** Synthetic regression cases only; this file runs in the isolated upgrade database. */
declare(strict_types=1);

use App\Consts\User as UserConst;
use App\Model\Config;
use App\Model\User;
use App\Util\Client;
use App\Util\Context;
use App\Util\Str;

$checks = 0;
$verify = static function (bool $condition, string $message) use (&$checks): void {
    check($condition, $message);
    $checks++;
};
$reject = static function (callable $run, string $message) use ($verify): string {
    try { $run(); } catch (Kernel\Exception\JSONException $error) {
        $verify(true, $message);
        return $error->getMessage();
    }
    throw new RuntimeException($message);
};
$settings = [];
foreach (['login_verification', 'admin_login_verification', 'ip_get_mode', 'trusted_proxy_ips', 'transfer_honeypot_enabled'] as $key) {
    $settings[$key] = Config::get($key);
}
$server = $_SERVER;
$setRequest = static function (object $controller, array $data): void {
    $_POST = $_REQUEST = $data;
    $_GET = [];
    $_COOKIE = [];
    $property = new ReflectionProperty($controller, 'request');
    $property->setAccessible(true);
    $property->setValue($controller, new Kernel\Context\Request());
};

try {
    Config::put('login_verification', '0');
    Client::setClientMode(0);
    $password = 'Security-fixture-password-123!';
    foreach ([1100 => 'security-owner', 1101 => 'security-other', 1102 => 'security-distributed'] as $id => $name) {
        $db->table('user')->insert(['id' => $id, 'username' => $name, 'password' => Str::hashPassword($password),
            'salt' => 'fixture', 'app_key' => 'fixture-' . $id, 'create_time' => date('Y-m-d H:i:s'), 'status' => 1]);
    }
    $owner = User::query()->findOrFail(1100);
    $other = User::query()->findOrFail(1101);
    $createOrder = static function (string $trade, int $owner, string $secret, string $password = '', int $status = 1): int {
        global $db;
        $id = addOrder($trade, 10);
        $db->table('order')->where('id', $id)->update(['owner' => $owner, 'status' => $status,
            'delivery_status' => $status, 'secret' => $secret, 'password' => $password,
            'contact' => 'security-shared-contact', 'leave_message' => 'PRIVATE-DELIVERY-NOTE']);
        return $id;
    };
    $ownId = $createOrder('889900000000000001', 1100, 'OWNER-CANARY');
    $otherId = $createOrder('889900000000000002', 1101, 'OTHER-CANARY');
    $guestId = $createOrder('889900000000000003', 0, 'GUEST-CANARY');
    $protectedId = $createOrder('889900000000000004', 0, 'PASSWORD-CANARY', 'query-fixture-password');
    $pendingId = $createOrder('889900000000000005', 0, 'PENDING-CANARY', '', 0);

    $index = new App\Controller\User\Api\Index();
    $property = new ReflectionProperty($index, 'query');
    $property->setAccessible(true);
    $property->setValue($index, new App\Service\Bind\Query());
    $list = static function (?User $buyer, string $keywords) use ($index, $setRequest): array {
        Context::set(UserConst::SESSION, $buyer);
        $setRequest($index, ['keywords' => $keywords, 'page' => 1, 'limit' => 100]);
        return $index->query()['data'];
    };
    $secret = static function (?User $buyer, string $trade, string $password = '') use ($index, $setRequest): array {
        Context::set(UserConst::SESSION, $buyer);
        $setRequest($index, ['tradeNo' => $trade, 'password' => $password]);
        return $index->secret()['data'];
    };
    $verify($list(null, '889900000000000001')['total'] === 0, 'Anonymous order lookup exposed a member order');
    $verify($list($other, '889900000000000001')['total'] === 0, 'Another member could list a private order');
    $verify($list($owner, '889900000000000001')['list'][0]['secret'] === 'OWNER-CANARY', 'Buyer lost access to own order');
    $guestRows = array_column($list(null, 'security-shared-contact')['list'], null, 'id');
    $verify(!isset($guestRows[$ownId]) && !isset($guestRows[$otherId]), 'Contact lookup bypassed buyer scope');
    $verify(isset($guestRows[$guestId], $guestRows[$protectedId], $guestRows[$pendingId]), 'Historical guest lookup stopped working');
    $verify($guestRows[$guestId]['secret'] === 'GUEST-CANARY', 'Guest delivery access was lost');
    $verify(!isset($guestRows[$protectedId]['secret']) && !isset($guestRows[$protectedId]['leave_message']) && !isset($guestRows[$protectedId]['commodity']['leave_message']), 'Password-protected delivery note was exposed');
    $verify(!isset($guestRows[$pendingId]['secret']) && !isset($guestRows[$pendingId]['leave_message']), 'Unpaid delivery content was exposed');
    $denied = $reject(fn() => $secret(null, '889900000000000001'), 'Anonymous caller could retrieve member delivery');
    $verify($reject(fn() => $secret($other, '889900000000000001'), 'Another buyer retrieved delivery') === $denied, 'Access error disclosed order existence');
    $verify($reject(fn() => $secret(null, '889900000000000099'), 'Missing order accepted') === $denied, 'Unknown and inaccessible order errors differ');
    $verify($secret($owner, '889900000000000001')['secret'] === 'OWNER-CANARY', 'Buyer secret lookup failed');
    $reject(fn() => $secret(null, '889900000000000004'), 'Guest query password was bypassed');
    $verify($secret(null, '889900000000000004', 'query-fixture-password')['secret'] === 'PASSWORD-CANARY', 'Guest query password no longer works');
    $reject(fn() => $secret(null, '889900000000000005'), 'Unpaid order returned delivery');

    Context::set(UserConst::SESSION, null);
    $auth = new App\Controller\User\Api\Authentication();
    $sso = new class implements App\Service\UserSSO {
        public array $issued = [];
        public function loginSuccess(User $user, bool $remember = false, bool $mfaSatisfied = false, string $via = ''): void { $this->issue($user, $remember, $via); }
        public function issue(User $user, bool $remember = false, string $via = ''): void { $this->issued[] = (int)$user->id; }
    };
    $property = new ReflectionProperty($auth, 'sso');
    $property->setAccessible(true);
    $property->setValue($auth, $sso);
    $login = static function (string $name, string $pw, string $ip) use ($auth, $setRequest): array {
        $_SERVER['REMOTE_ADDR'] = $ip;
        $setRequest($auth, ['username' => $name, 'password' => $pw]);
        return $auth->login();
    };
    $verify((int)App\Util\AccountResolver::resolve('sécúrity-owner')->id === 1100, 'Fixture does not exercise MySQL accent equivalence');
    for ($i = 0; $i < 10; $i++) {
        $reject(fn() => $login('security-owner', 'wrong-fixture-password', '198.51.100.201'), 'Wrong password accepted');
    }
    $verify(str_contains($reject(fn() => $login('sécúrity-owner', $password, '198.51.100.201'), 'Accent alias bypassed account throttle'), '频繁'), 'Alias should share the exhausted member budget');

    for ($i = 0; $i < 20; $i++) {
        $reject(fn() => $login('missing-before-' . $i, 'wrong-fixture-password', '198.51.100.202'), 'Unknown account accepted');
    }
    $verify($login('security-other', $password, '198.51.100.202')['code'] === 200, 'Valid login failed before IP budget exhausted');
    for ($i = 0; $i < 9; $i++) {
        $reject(fn() => $login('missing-after-' . $i, 'wrong-fixture-password', '198.51.100.202'), 'Unknown account accepted');
    }
    $verify(str_contains($reject(fn() => $login('security-other', $password, '198.51.100.202'), 'Successful login reset the shared IP budget'), '频繁'), 'IP attempt budget must survive successful logins');

    for ($i = 1; $i <= 30; $i++) {
        $reject(fn() => $login('security-distributed', 'wrong-fixture-password', '203.0.113.' . $i), 'Distributed wrong password accepted');
    }
    $verify(str_contains($reject(fn() => $login('security-distributed', $password, '203.0.113.200'), 'Changing IP bypassed member attempt budget'), '频繁'), 'Distributed attempts need a shared member budget');
    $verify($login('security-other', $password, '198.51.100.220')['code'] === 200, 'An unrelated account was blocked');

    Client::setTrustedProxyConfig('172.18.0.1');
    Client::setClientMode(1);
    $_SERVER['REMOTE_ADDR'] = '172.18.0.1';
    $_SERVER['HTTP_X_REAL_IP'] = '198.51.100.90';
    $_SERVER['HTTP_CF_CONNECTING_IP'] = '192.0.2.99';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.98';
    $verify(Client::getAddress() === '198.51.100.90', 'Trusted proxy did not provide the real client IP');
    $_SERVER['REMOTE_ADDR'] = '198.51.100.5';
    $verify(Client::getAddress() === '198.51.100.5', 'An untrusted client spoofed forwarding headers');
    $_SERVER['REMOTE_ADDR'] = '172.18.0.1';
    $_SERVER['HTTP_X_REAL_IP'] = '2001:db8::90';
    $verify(Client::getAddress() === '2001:db8::90', 'IPv6 client IP was lost');
    $_SERVER['HTTP_X_REAL_IP'] = '198.51.100.90, 198.51.100.91';
    $verify(Client::getAddress() === '172.18.0.1', 'A forged single-address header list was accepted');
    Config::put('transfer_honeypot_enabled', '0');
    $verify(!App\Util\TransferHoneypot::enabled(), 'Transfer honeypot did not close');
    require __DIR__ . '/security_followup.php';
    echo "PASS security hardening ({$checks} assertions)\n";
} finally {
    Context::set(UserConst::SESSION, null);
    Context::set(App\Consts\Manage::SESSION, null);
    $_SERVER = $server;
    foreach ($settings as $key => $value) Config::put($key, $value);
    Client::setClientMode((int)$settings['ip_get_mode']);
    Client::setTrustedProxyConfig($settings['trusted_proxy_ips']);
}
