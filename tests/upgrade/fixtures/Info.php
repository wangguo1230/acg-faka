<?php
use App\Consts\Pay;
return [
    'name' => 'Upgrade test gateway', 'options' => ['alipay' => 'Alipay'],
    'callback' => [Pay::IS_SIGN => true, Pay::IS_STATUS => true,
        Pay::FIELD_ORDER_KEY => 'out_trade_no', Pay::FIELD_AMOUNT_KEY => 'money',
        Pay::FIELD_STATUS_KEY => 'trade_status', Pay::FIELD_STATUS_VALUE => 'TRADE_SUCCESS',
        Pay::FIELD_RESPONSE => 'success'],
];
