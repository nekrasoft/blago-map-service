<?php
$authLogin = getenv('ADMIN_LOGIN') ?: 'admin';
$authHash  = getenv('ADMIN_PASSWORD_HASH');
$demoLogin = getenv('DEMO_LOGIN') ?: 'demo';
$demoHash  = getenv('DEMO_PASSWORD_HASH');

$users = [];
if ($authHash) $users[$authLogin] = $authHash;
if ($demoHash) $users[$demoLogin] = $demoHash;

return [
    'yandexMapsApiKey' => getenv('YANDEX_MAPS_API_KEY') ?: 'YOUR_KEY_HERE',

    // Пользователи: admin — полный доступ, demo — только чтение
    'users'         => $users,
    'readonlyUsers' => $demoHash ? [$demoLogin] : [],
    'counterpartyAccess' => [],

    // API-ключ для бота отчётов (запись без сессии)
    'botApiKey' => getenv('MAP_BOT_API_KEY') ?: '',

    // API-ключ для read-only бота (GET /api/bunkers и /api/counterparties)
    'botReadApiKey' => getenv('MAP_BOT_READ_API_KEY') ?: '',

    // Опциональный allowlist IP для botApiKey/botReadApiKey (через запятую)
    'botAllowedIps' => getenv('MAP_BOT_ALLOWED_IPS') ?: '',

    // Интеграция с MAX (опционально): уведомление в чат заявок при mark-filled
    'maxBotToken' => getenv('MAX_BOT_TOKEN') ?: '',
    'maxRequestChatId' => getenv('MAX_REQUEST_CHAT_ID') ?: '',
];
