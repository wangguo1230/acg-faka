<?php
/** Real HTTP checks against the isolated candidate container, never production. */
declare(strict_types=1);

$base = (string)getenv('ACG_TEST_HTTP_BASE');
check(preg_match('~^http://acg-upgrade-[a-f0-9]+-app$~D', $base) === 1, 'Refusing HTTP tests outside the isolated upgrade network');
$request = static function (string $path, ?array $data = null, string $cookie = '') use ($base): array {
    $headers = "User-Agent: IsolatedUpgradeSecurityTest\r\nReferer: {$base}/\r\n";
    if ($cookie !== '') $headers .= "Cookie: {$cookie}\r\n";
    if ($data !== null) $headers .= "Content-Type: application/x-www-form-urlencoded\r\n";
    $context = stream_context_create(['http' => ['method' => $data === null ? 'GET' : 'POST',
        'header' => $headers, 'content' => $data === null ? '' : http_build_query($data),
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 15]]);
    $body = file_get_contents($base . $path, false, $context);
    $responseHeaders = $http_response_header ?? [];
    preg_match('/^HTTP\/\S+\s+(\d+)/', $responseHeaders[0] ?? '', $match);
    return ['status' => (int)($match[1] ?? 0), 'headers' => $responseHeaders, 'body' => $body,
        'json' => is_string($body) ? json_decode($body, true) : null];
};

$before = App\Model\Config::get('login_verification');
try {
    App\Model\Config::put('login_verification', '0');
    $home = $request('/');
    check($home['status'] === 200 && str_contains((string)$home['body'], '<html'), 'Storefront template failed after dependency upgrade');
    $loginPage = $request('/user/authentication/login');
    check($loginPage['status'] === 200, 'Member login template failed after Smarty upgrade');
    $login = $request('/user/api/authentication/login', ['username' => 'security-owner', 'password' => 'Security-fixture-password-123!']);
    check(($login['json']['code'] ?? null) === 200, 'Real member login failed after JWT upgrade');
    $cookie = '';
    foreach ($login['headers'] as $header) {
        if (!preg_match('/^Set-Cookie:\s*' . preg_quote(App\Consts\User::SESSION, '/') . '=([^;]+);(.*)$/i', $header, $match)) continue;
        check(str_contains(strtolower($match[2]), 'httponly'), 'Member session cookie is readable by JavaScript');
        check(str_contains(strtolower($match[2]), 'samesite=lax'), 'Member session cookie lost SameSite protection');
        $cookie = App\Consts\User::SESSION . '=' . $match[1];
    }
    check($cookie !== '', 'Login did not issue a member session cookie');
    $private = $request('/user/api/purchaseRecord/data', ['page' => 1, 'limit' => 10], $cookie);
    check(($private['json']['code'] ?? null) === 200, 'Issued JWT did not authenticate a real HTTP request');
    check((bool)array_filter($private['headers'], static fn(string $header): bool =>
        str_starts_with(strtolower($header), 'set-cookie: ' . strtolower(App\Consts\User::SESSION) . '=')
        && str_contains(strtolower($header), 'httponly')), 'An existing member session did not receive hardened cookie flags');
    check((bool)array_filter($private['headers'], static fn(string $header): bool =>
        str_starts_with(strtolower($header), 'cache-control:') && str_contains(strtolower($header), 'no-store')),
        'Authenticated response could be stored in a shared cache');
    $other = $request('/user/api/index/query', ['keywords' => '889900000000000002', 'page' => 1, 'limit' => 10], $cookie);
    check(($other['json']['data']['total'] ?? null) === 0, 'Dependency upgrade regressed order ownership protection');
    $anonymousState = $request('/user/api/order/state', ['tradeNo' => '889900000000000001']);
    check(($anonymousState['json']['code'] ?? null) !== 200 && !isset($anonymousState['json']['data']['amount']), 'Anonymous HTTP caller read a member payment status');
    $ownState = $request('/user/api/order/state', ['tradeNo' => '889900000000000001'], $cookie);
    check(($ownState['json']['code'] ?? null) === 200, 'Member HTTP payment polling failed');
    $submit = Kernel\Util\View::render('User/Submit.html', ['url' => 'https://gateway.invalid/pay',
        'data' => ['out_trade_no' => 'fixture-order', 'money' => '10.00']], controller: false);
    check(str_contains($submit, 'fixture-order') && str_contains($submit, 'https://gateway.invalid/pay'), 'Payment form template failed after Smarty upgrade');
    echo "PASS real HTTP login, HttpOnly cookie, JWT authorization, order scope and Smarty/payment templates\n";
} finally {
    App\Model\Config::put('login_verification', $before);
}
