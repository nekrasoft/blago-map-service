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

    // API-ключ для бота отчётов (запись fillLevel без сессии)
    'botApiKey' => getenv('MAP_BOT_API_KEY') ?: '',
];
