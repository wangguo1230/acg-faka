<?php
declare(strict_types=1);

// 后台「插件管理 → 配置」渲染的表单。各项 name 对应 Config.php 中的键。
return [
    [
        "title" => "Bot Token",
        "name" => "bot_token",
        "type" => "input",
        "placeholder" => "BotFather 申请的 Token，形如 123456:ABC-DEF...",
    ],
    [
        "title" => "Chat ID",
        "name" => "chat_id",
        "type" => "input",
        "placeholder" => "接收通知的会话 ID（个人/群组/频道），可填多个用英文逗号分隔",
    ],
    [
        "title" => "API 代理地址",
        "name" => "api_base",
        "type" => "input",
        "placeholder" => "默认 https://api.telegram.org ，国内服务器可填反代地址",
    ],
    [
        "title" => "客户付款通知",
        "name" => "notify_paid",
        "type" => "switch",
        "text" => "开启",
    ],
    [
        "title" => "待人工发货提醒",
        "name" => "notify_pending",
        "type" => "switch",
        "text" => "开启（手动/插件发货商品付款后提醒去发货）",
    ],
    [
        "title" => "人工发货完成通知",
        "name" => "notify_manual",
        "type" => "switch",
        "text" => "开启（管理员后台点发货后通知）",
    ],
];
