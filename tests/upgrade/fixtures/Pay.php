<?php
namespace App\Pay\Epay\Impl;

class Pay extends \App\Pay\Base
{
    public function trade(): \App\Entity\PayEntity
    {
        file_put_contents(BASE_PATH . '/runtime/test-gateway.json', json_encode([
            'trade' => $this->tradeNo, 'amount' => $this->amount, 'config' => $this->config,
            'callback' => $this->callbackUrl, 'return' => $this->returnUrl,
        ]));
        $entity = new \App\Entity\PayEntity();
        $entity->setType(\App\Pay\Pay::TYPE_REDIRECT);
        $entity->setUrl('https://gateway.invalid/pay/' . $this->tradeNo);
        return $entity;
    }
}
