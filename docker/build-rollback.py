#!/usr/bin/env python3
"""Build a local 3.5.4 rollback image that understands payments created by 3.7.6."""
import argparse
import pathlib
import re
import shutil
import subprocess
import tempfile


def method_span(source, name):
    match = re.search(r'^    (?:public|private|protected) (?:static )?function ' + re.escape(name) + r'\(', source, re.M)
    if not match:
        raise RuntimeError(f'Method {name} not found; unsupported base image')
    opening = source.index('{', match.end())
    # Skip strings and comments so interpolated braces do not change the method boundary.
    tokens = re.compile(r'''/\*.*?\*/|//[^\n]*|'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"|[{}]''', re.S)
    depth = 0
    for token in tokens.finditer(source, opening):
        if token.group() == '{':
            depth += 1
        elif token.group() == '}':
            depth -= 1
            if depth == 0:
                return match.start(), token.end()
    raise RuntimeError(f'Unclosed method {name}')


def method(source, name):
    start, end = method_span(source, name)
    return source[start:end]


def replace_method(source, name, replacement):
    start, end = method_span(source, name)
    return source[:start] + replacement + source[end:]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--base', default='ghcr.io/wangguo1230/acg-faka:sha-9927235')
    parser.add_argument('--tag', default='acg-faka:3.5.4-upgrade-rollback')
    args = parser.parse_args()
    if args.tag.endswith(':latest'):
        raise SystemExit('Use a dedicated rollback tag, not latest.')
    repo = pathlib.Path(__file__).resolve().parents[1]
    subprocess.run(['docker', 'image', 'inspect', args.base], check=True, stdout=subprocess.DEVNULL)
    with tempfile.TemporaryDirectory(prefix='acg-rollback-build-') as temp:
        root = pathlib.Path(temp)
        container = subprocess.check_output(['docker', 'create', '--network', 'none', args.base]).decode().strip()
        files = ['app/Service/Bind/Order.php', 'app/Service/Bind/Recharge.php', 'app/Service/Order.php', 'app/Controller/Admin/Api/Pay.php']
        try:
            for file in files:
                target = root / 'patch' / file
                target.parent.mkdir(parents=True, exist_ok=True)
                subprocess.run(['docker', 'cp', f'{container}:/var/www/html/{file}', str(target)], check=True)
        finally:
            subprocess.run(['docker', 'rm', container], check=True, stdout=subprocess.DEVNULL)

        for service in ('Order', 'Recharge'):
            path = f'app/Service/Bind/{service}.php'
            old = (root / 'patch' / path).read_text()
            current = (repo / path).read_text()
            old = replace_method(old, 'callback', method(current, 'callback'))
            if service == 'Order':
                old = replace_method(old, 'callbackInitialize', method(current, 'callbackInitialize'))
                old = replace_method(old, 'payCredentialConfigured', method(current, 'payCredentialConfigured'))
                extra = '\n    public const CALLBACK_REJECT = "fail";\n\n'
                extra += method(current, 'callbackFail') + '\n\n' + method(current, 'isCallbackTradeNo') + '\n'
                closing = old.rfind('}')
                old = old[:closing] + extra + old[closing:]
            # After rollback new payments must still target numeric callback URLs and the bound account.
            old = old.replace('$payObject->config = PayConfig::config($pay->handle);', '''$payObject->config = (\\App\\Util\\Schema::tableExists('pay_config') && (int)$pay->pay_config_id > 0)
                ? \\App\\Util\\PayProfile::config($pay) : PayConfig::config($pay->handle);''')
            old = re.sub(r"(\$payObject->callbackUrl = [^;]+?\.) \$pay->handle;", r"\1 ((\\App\\Util\\Schema::tableExists('pay_config') && (int)$pay->pay_config_id > 0) ? $order->trade_no : $pay->handle);", old)
            old = old.replace('$payObject->returnUrl = $callbackDomain', '$payObject->returnUrl = $clientDomain')
            start, _ = method_span(old, 'trade')
            opening = old.index('{', start) + 1
            old = old[:opening] + '''
        $currency = strtoupper((string)\\App\\Model\\Config::get('currency_code'));
        if ($currency !== '' && $currency !== 'CNY') {
            throw new \\Kernel\\Exception\\JSONException('兼容回滚仅支持人民币新下单，请恢复升级版后使用其他币种；已有订单仍可处理支付通知');
        }
''' + old[opening:]
            (root / 'patch' / path).write_text(old)

        interface = root / 'patch/app/Service/Order.php'
        source = interface.read_text()
        source, count = re.subn(r'function callbackInitialize\(string\s+\$handle,\s*array\s+\$map\)',
            lambda _: 'function callbackInitialize(\\App\\Model\\Pay $pay, array $map, ?array $payConfig = null)', source)
        if count != 1:
            raise RuntimeError('Unsupported Order interface in rollback image')
        interface.write_text(source)
        pay = root / 'patch/app/Controller/Admin/Api/Pay.php'
        source = pay.read_text()
        for name in ('save', 'del', 'setPluginConfig'):
            start, _ = method_span(source, name)
            opening = source.index('{', start) + 1
            source = source[:opening] + '''
        if (\\App\\Util\\Schema::tableExists('pay_config')) {
            throw new \\Kernel\\Exception\\JSONException('兼容回滚期间保留现有支付配置，请恢复升级版后修改商户配置');
        }
''' + source[opening:]
        pay.write_text(source)

        for file in ('app/Util/Schema.php', 'app/Util/PayProfile.php', 'app/Util/LegacyPayCallback.php', 'app/Model/PayConfig.php'):
            target = root / 'patch' / file
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(repo / file, target)
        resolver = root / 'patch/app/Util/LegacyPayCallback.php'
        source = resolver.read_text()
        source = source.replace('        if ($legacy && $snapshot === null) {', '''        if ($legacy && $snapshot === null
            && (!Schema::tableExists('docker_migration')
                || !DB::table('docker_migration')->where('version', 'docker-3.7.6-pay-v2')->exists())) {
            // Before a completed upgrade, preserve the original file-backed merchant.
            return [$order, PayConfig::config($handle)];
        }
        if ($legacy && $snapshot === null) {''', 1)
        resolver.write_text(source)
        shutil.copy2(repo / 'docker/entrypoint.sh', root / 'entrypoint.sh')
        (root / 'healthcheck.php').write_text('''<?php
error_reporting(0);
try {
    $socket = @fsockopen('127.0.0.1', 80, $errno, $error, 2);
    if (!$socket) exit(1);
    fclose($socket);
    if (!is_file('/var/www/html/kernel/Install/Lock')) exit(0);
    require '/var/www/html/vendor/autoload.php';
    $config = require '/var/www/html/config/database.php';
    $config['options'][PDO::ATTR_TIMEOUT] = 3;
    $capsule = new \\Illuminate\\Database\\Capsule\\Manager();
    $capsule->addConnection($config);
    $db = $capsule->getConnection();
    $db->table('manage')->limit(1)->get(['id']);
    $db->table('pay')->limit(1)->get(['id', 'handle']);
    $db->table('order')->limit(1)->get(['id', 'amount']);
    $db->table('user_recharge')->limit(1)->get(['id', 'pay_cost']);
    exit(0);
} catch (Throwable) { exit(1); }
''')
        (root / 'Dockerfile').write_text('ARG BASE_IMAGE=ghcr.io/wangguo1230/acg-faka:sha-9927235\nFROM ${BASE_IMAGE}\n'
            'COPY --chown=www-data:www-data patch/ /var/www/html/\n'
            'COPY entrypoint.sh /usr/local/bin/acg-faka-entrypoint\n'
            'COPY healthcheck.php /usr/local/bin/acg-faka-rollback-healthcheck.php\n'
            'RUN chmod +x /usr/local/bin/acg-faka-entrypoint && composer dump-autoload --optimize --no-interaction\n'
            'HEALTHCHECK --interval=30s --timeout=10s --start-period=120s --retries=3 CMD ["php", "/usr/local/bin/acg-faka-rollback-healthcheck.php"]\n'
            'LABEL acg.rollback.compatibility="3.5.4-with-3.7.6-payments"\n')
        subprocess.run(['docker', 'build', '--platform', 'linux/amd64', '--build-arg', 'BASE_IMAGE=' + args.base,
                        '-t', args.tag, str(root)], check=True)
        print('Built local rollback image:', args.tag)


if __name__ == '__main__':
    main()
