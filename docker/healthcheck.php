<?php
declare(strict_types=1);

// No application hooks, gateway calls, sessions or business writes in health checks.
const BASE_PATH = '/var/www/html';
error_reporting(0);
try {
    $socket = @fsockopen('127.0.0.1', 80, $errno, $error, 2);
    if (!$socket) {
        exit(1);
    }
    fclose($socket);
    if (!is_file(BASE_PATH . '/kernel/Install/Lock')) {
        exit(0);
    }
    require BASE_PATH . '/vendor/autoload.php';
    $capsule = new \Illuminate\Database\Capsule\Manager();
    $config = require BASE_PATH . '/config/database.php';
    $config['options'][PDO::ATTR_TIMEOUT] = 3;
    $capsule->addConnection($config);
    $db = $capsule->getConnection();
    $db->table('manage_session')->limit(1)->get(['id', 'session_hash']);
    $db->table('pay')->limit(1)->get(['id', 'pay_config_id', 'archived']);
    $db->table('pay_config')->limit(1)->get(['id']);
    $db->table('order')->limit(1)->get(['id', 'gateway_amount', 'leave_message']);
    $db->table('user_recharge')->limit(1)->get(['id', 'gateway_amount', 'pay_cost']);
    exit(0);
} catch (Throwable) {
    exit(1);
}
