<?php
$authLogin = getenv('ADMIN_LOGIN') ?: 'admin';
$authHash  = getenv('ADMIN_PASSWORD_HASH');

return [
    'yandexMapsApiKey' => getenv('YANDEX_MAPS_API_KEY') ?: 'YOUR_KEY_HERE',

    // Пользователи для авторизации. Логин и хеш пароля из .env
    // Без ADMIN_PASSWORD_HASH доступ без авторизации.
    // Хеш: php -r "echo password_hash('пароль', PASSWORD_DEFAULT);"
    'users' => $authHash ? [$authLogin => $authHash] : [],
];
