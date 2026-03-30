<?php
$authLogin = getenv('ADMIN_LOGIN') ?: 'admin';
$authHash  = getenv('ADMIN_PASSWORD_HASH');
$demoLogin = getenv('DEMO_LOGIN') ?: 'demo';
$demoHash  = getenv('DEMO_PASSWORD_HASH');

$users = [];
if ($authHash) $users[$authLogin] = $authHash;
if ($demoHash) $users[$demoLogin] = $demoHash;

$counterpartyAccess = [];
$counterpartyUsersJson = getenv('COUNTERPARTY_USERS_JSON') ?: '';
if ($counterpartyUsersJson) {
    $counterpartyUsers = json_decode($counterpartyUsersJson, true);
    if (is_array($counterpartyUsers)) {
        foreach ($counterpartyUsers as $item) {
            if (!is_array($item)) {
                continue;
            }

            $login = trim((string) ($item['login'] ?? ''));
            $passwordHash = trim((string) ($item['passwordHash'] ?? ''));
            $counterpartyId = (int) ($item['counterpartyId'] ?? 0);

            if ($login === '' || $passwordHash === '' || $counterpartyId <= 0) {
                continue;
            }

            $users[$login] = $passwordHash;
            $counterpartyAccess[$login] = $counterpartyId;
        }
    }
}

return [
    'yandexMapsApiKey' => getenv('YANDEX_MAPS_API_KEY') ?: 'YOUR_KEY_HERE',

    // Пользователи: admin — полный доступ, demo — только чтение
    'users'         => $users,
    'readonlyUsers' => $demoHash ? [$demoLogin] : [],
    'counterpartyAccess' => $counterpartyAccess,

    // API-ключ для бота отчётов (запись fillLevel без сессии)
    'botApiKey' => getenv('MAP_BOT_API_KEY') ?: '',
];
