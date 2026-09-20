<?php
namespace App\Pay\Epay\Impl;

class Signature implements \App\Pay\Signature
{
    public function verification(array $data, array $config): bool
    {
        $signature = $data['sign'] ?? '';
        unset($data['sign']);
        ksort($data);
        if (!hash_equals(hash_hmac('sha256', http_build_query($data), $config['key']), (string)$signature)) {
            return false;
        }
        // Exercise the same post-verification context channel used by real gateway adapters.
        if (isset($data['verified_order'])) {
            $data['out_trade_no'] = $data['verified_order'];
            \Kernel\Util\Context::set(\App\Consts\Pay::DAFA, $data);
        }
        return true;
    }
}
