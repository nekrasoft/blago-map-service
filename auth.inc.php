<?php
session_start();

$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val, " \t\n\r\"'");
        putenv("$key=$val");
        $_ENV[$key] = $val;
    }
}

$config = file_exists(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];

function isAuthed($config) {
    if (empty($config['users'])) return true;
    return isset($_SESSION['user']);
}

/** Редирект на логин, если не авторизован */
function requireMapAuth($config) {
    if (!isAuthed($config)) {
        header('Location: /login');
        exit;
    }
}

/** Редирект на карту, если уже авторизован */
function requireLoginPage($config) {
    if (isAuthed($config)) {
        header('Location: /');
        exit;
    }
}
