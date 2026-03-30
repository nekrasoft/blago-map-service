<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

$configFile = __DIR__ . '/config.php';
$legacyDataFile = __DIR__ . '/data/bunkers.json';
$envFile = __DIR__ . '/.env';

// --- Загрузка .env ---
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val, " \t\n\r\"'");
        putenv("$key=$val");
        $_ENV[$key] = $val;
    }
}

// --- Конфигурация ---
$config = file_exists($configFile) ? require $configFile : [];

// --- Маршрутизация ---
$method = $_SERVER['REQUEST_METHOD'];
$route = isset($_GET['route']) ? trim($_GET['route'], '/') : '';
$id = isset($_GET['id']) ? $_GET['id'] : null;

// --- Утилиты ---
function readLegacyBunkers($file)
{
    if (!file_exists($file)) {
        return [];
    }
    $json = file_get_contents($file);
    return json_decode($json, true) ?: [];
}

function normalizeAddress($address)
{
    if (!is_string($address)) {
        return '';
    }

    $normalized = trim($address);
    if ($normalized === '') {
        return '';
    }

    // Удаляем сегмент вида ", микрорайон ...", чтобы адреса не дублировались при группировке.
    $normalized = preg_replace('/\s*,\s*(?:микрорайон|мкр\.?)\s+[^,]+/iu', '', $normalized);
    $normalized = preg_replace('/\s*,\s*,+/u', ',', $normalized);
    $normalized = preg_replace('/\s*,\s*/u', ', ', $normalized);
    $normalized = preg_replace('/\s{2,}/u', ' ', $normalized);

    return trim($normalized, " \t\n\r\0\x0B,");
}

function getRequestBody()
{
    return json_decode(file_get_contents('php://input'), true) ?: [];
}

function generateId()
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

function jsonResponse($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function writeAppLog($level, $message, $context = [])
{
    $logFile = getenv('APP_LOG_FILE') ?: (__DIR__ . '/logs/api-error.log');
    $logDir = dirname($logFile);

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    $record = [
        'time' => date('c'),
        'level' => $level,
        'message' => $message,
        'request' => [
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'route' => $_GET['route'] ?? '',
            'id' => $_GET['id'] ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ],
        'context' => $context,
    ];

    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        $line = date('c') . ' [' . $level . '] ' . $message;
    }

    if (@file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
        error_log('map-service: failed to write app log');
    }
}

function logThrowable($message, $e, $context = [])
{
    $context['exception'] = [
        'class' => get_class($e),
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ];
    writeAppLog('error', $message, $context);
}

function isAuthed($config)
{
    if (empty($config['users'])) {
        return true; // без настройки users — доступ без авторизации
    }
    return isset($_SESSION['user']);
}

/** Проверка API-ключа бота (X-API-Key или Authorization: Bearer) — для записи без сессии */
function isBotAuthed($config)
{
    $key = $config['botApiKey'] ?? '';
    if (!$key) {
        return false;
    }
    $header = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($header === $key) {
        return true;
    }
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', trim($auth), $m) && $m[1] === $key) {
        return true;
    }
    return false;
}

function requireAuth($config)
{
    if (!isAuthed($config)) {
        jsonResponse(['error' => 'Требуется авторизация'], 401);
    }
}

function requireWriteAuth($config)
{
    if (isBotAuthed($config)) {
        return; // бот с API-ключом — пропускаем
    }
    requireAuth($config);
    if (!empty($_SESSION['readonly'])) {
        jsonResponse(['error' => 'Доступ только для чтения'], 403);
    }
}

function getMysqlConnection()
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!class_exists('PDO')) {
        throw new RuntimeException('PDO extension is not available');
    }
    if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('PDO MySQL driver is not installed');
    }

    $host = getenv('MYSQL_HOST') ?: 'localhost';
    $port = getenv('MYSQL_PORT') ?: '3306';
    $user = getenv('MYSQL_USER') ?: 'map_service';
    $password = getenv('MYSQL_PASSWORD') ?: '';
    $database = getenv('MYSQL_DATABASE') ?: 'map_service';

    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function initBunkersTable($pdo)
{
    static $initialized = false;

    if ($initialized) {
        return;
    }

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS bunkers (
    id VARCHAR(64) NOT NULL,
    `number` INT NOT NULL DEFAULT 0,
    volume DECIMAL(10,2) NOT NULL DEFAULT 8.00,
    address VARCHAR(255) NOT NULL DEFAULT '',
    district VARCHAR(255) NOT NULL DEFAULT '',
    contractor VARCHAR(255) NOT NULL DEFAULT '',
    waste_type VARCHAR(100) NOT NULL DEFAULT 'КГО',
    last_pickup_date VARCHAR(10) NOT NULL DEFAULT '',
    fill_level INT NOT NULL DEFAULT 0,
    contact_phone VARCHAR(50) NOT NULL DEFAULT '',
    lat DECIMAL(17,14) NOT NULL DEFAULT 0,
    lng DECIMAL(17,14) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bunkers_number (`number`),
    KEY idx_bunkers_district (district),
    KEY idx_bunkers_waste_type (waste_type),
    KEY idx_bunkers_contractor (contractor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    $pdo->exec($sql);
    $initialized = true;
}

function migrateLegacyJsonIfNeeded($pdo, $legacyFile)
{
    static $migrated = false;

    if ($migrated) {
        return;
    }

    $rowsCount = (int) $pdo->query('SELECT COUNT(*) FROM bunkers')->fetchColumn();
    if ($rowsCount > 0 || !file_exists($legacyFile)) {
        $migrated = true;
        return;
    }

    $legacyBunkers = readLegacyBunkers($legacyFile);
    if (!$legacyBunkers) {
        $migrated = true;
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO bunkers (id, `number`, volume, address, district, contractor, waste_type, last_pickup_date, fill_level, contact_phone, lat, lng)
         VALUES (:id, :number, :volume, :address, :district, :contractor, :wasteType, :lastPickupDate, :fillLevel, :contactPhone, :lat, :lng)'
    );

    $pdo->beginTransaction();
    try {
        foreach ($legacyBunkers as $index => $bunker) {
            $stmt->execute([
                'id' => (string) ($bunker['id'] ?? generateId()),
                'number' => array_key_exists('number', $bunker) ? (int) $bunker['number'] : ($index + 1),
                'volume' => array_key_exists('volume', $bunker) ? (float) $bunker['volume'] : 8,
                'address' => normalizeAddress($bunker['address'] ?? ''),
                'district' => (string) ($bunker['district'] ?? ''),
                'contractor' => (string) ($bunker['contractor'] ?? ''),
                'wasteType' => (string) ($bunker['wasteType'] ?? 'КГО'),
                'lastPickupDate' => (string) ($bunker['lastPickupDate'] ?? date('Y-m-d')),
                'fillLevel' => array_key_exists('fillLevel', $bunker) ? (int) $bunker['fillLevel'] : 0,
                'contactPhone' => (string) ($bunker['contactPhone'] ?? ''),
                'lat' => array_key_exists('lat', $bunker) ? (float) $bunker['lat'] : 0,
                'lng' => array_key_exists('lng', $bunker) ? (float) $bunker['lng'] : 0,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $migrated = true;
}

function mapBunkerRow($row)
{
    return [
        'id' => (string) $row['id'],
        'number' => (int) $row['number'],
        'volume' => (float) $row['volume'],
        'address' => normalizeAddress($row['address'] ?? ''),
        'district' => (string) ($row['district'] ?? ''),
        'contractor' => (string) ($row['contractor'] ?? ''),
        'wasteType' => (string) ($row['wasteType'] ?? 'КГО'),
        'lastPickupDate' => (string) ($row['lastPickupDate'] ?? ''),
        'fillLevel' => (int) ($row['fillLevel'] ?? 0),
        'contactPhone' => (string) ($row['contactPhone'] ?? ''),
        'lat' => (float) ($row['lat'] ?? 0),
        'lng' => (float) ($row['lng'] ?? 0),
    ];
}

function getBunkersDb($legacyFile)
{
    static $ready = false;
    $pdo = getMysqlConnection();

    if (!$ready) {
        initBunkersTable($pdo);
        migrateLegacyJsonIfNeeded($pdo, $legacyFile);
        $ready = true;
    }

    return $pdo;
}

function listBunkers($pdo, $filters = [])
{
    $sql = 'SELECT id, `number`, volume, address, district, contractor, waste_type AS wasteType, last_pickup_date AS lastPickupDate, fill_level AS fillLevel, contact_phone AS contactPhone, lat, lng
            FROM bunkers';

    $where = [];
    $params = [];

    if (!empty($filters['district'])) {
        $where[] = 'district = :district';
        $params['district'] = $filters['district'];
    }
    if (!empty($filters['wasteType'])) {
        $where[] = 'waste_type = :wasteType';
        $params['wasteType'] = $filters['wasteType'];
    }
    if (!empty($filters['contractor'])) {
        $where[] = 'contractor = :contractor';
        $params['contractor'] = $filters['contractor'];
    }

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY `number` ASC, id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    return array_map('mapBunkerRow', $rows);
}

function getBunkerById($pdo, $id)
{
    $stmt = $pdo->prepare(
        'SELECT id, `number`, volume, address, district, contractor, waste_type AS wasteType, last_pickup_date AS lastPickupDate, fill_level AS fillLevel, contact_phone AS contactPhone, lat, lng
         FROM bunkers
         WHERE id = :id'
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    return mapBunkerRow($row);
}

function createBunker($pdo, $body)
{
    $nextNumber = (int) $pdo->query('SELECT COUNT(*) FROM bunkers')->fetchColumn() + 1;
    $newBunker = [
        'id' => generateId(),
        'number' => array_key_exists('number', $body) ? (int) $body['number'] : $nextNumber,
        'volume' => array_key_exists('volume', $body) ? (float) $body['volume'] : 8,
        'address' => normalizeAddress($body['address'] ?? ''),
        'district' => (string) ($body['district'] ?? ''),
        'contractor' => (string) ($body['contractor'] ?? ''),
        'wasteType' => (string) ($body['wasteType'] ?? 'КГО'),
        'lastPickupDate' => (string) ($body['lastPickupDate'] ?? date('Y-m-d')),
        'fillLevel' => array_key_exists('fillLevel', $body) ? (int) $body['fillLevel'] : 0,
        'contactPhone' => (string) ($body['contactPhone'] ?? ''),
        'lat' => array_key_exists('lat', $body) ? (float) $body['lat'] : 0,
        'lng' => array_key_exists('lng', $body) ? (float) $body['lng'] : 0,
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO bunkers (id, `number`, volume, address, district, contractor, waste_type, last_pickup_date, fill_level, contact_phone, lat, lng)
         VALUES (:id, :number, :volume, :address, :district, :contractor, :wasteType, :lastPickupDate, :fillLevel, :contactPhone, :lat, :lng)'
    );

    $stmt->execute([
        'id' => $newBunker['id'],
        'number' => $newBunker['number'],
        'volume' => $newBunker['volume'],
        'address' => $newBunker['address'],
        'district' => $newBunker['district'],
        'contractor' => $newBunker['contractor'],
        'wasteType' => $newBunker['wasteType'],
        'lastPickupDate' => $newBunker['lastPickupDate'],
        'fillLevel' => $newBunker['fillLevel'],
        'contactPhone' => $newBunker['contactPhone'],
        'lat' => $newBunker['lat'],
        'lng' => $newBunker['lng'],
    ]);

    return $newBunker;
}

function updateBunker($pdo, $id, $body)
{
    $fieldMap = [
        'number' => ['column' => '`number`', 'type' => 'int'],
        'volume' => ['column' => 'volume', 'type' => 'float'],
        'address' => ['column' => 'address', 'type' => 'address'],
        'district' => ['column' => 'district', 'type' => 'string'],
        'contractor' => ['column' => 'contractor', 'type' => 'string'],
        'wasteType' => ['column' => 'waste_type', 'type' => 'string'],
        'lastPickupDate' => ['column' => 'last_pickup_date', 'type' => 'string'],
        'fillLevel' => ['column' => 'fill_level', 'type' => 'int'],
        'contactPhone' => ['column' => 'contact_phone', 'type' => 'string'],
        'lat' => ['column' => 'lat', 'type' => 'float'],
        'lng' => ['column' => 'lng', 'type' => 'float'],
    ];

    $set = [];
    $params = ['id' => $id];

    foreach ($fieldMap as $apiField => $meta) {
        if (!array_key_exists($apiField, $body)) {
            continue;
        }

        $value = $body[$apiField];
        if ($meta['type'] === 'int') {
            $value = (int) $value;
        } elseif ($meta['type'] === 'float') {
            $value = (float) $value;
        } elseif ($meta['type'] === 'address') {
            $value = normalizeAddress($value);
        } else {
            $value = (string) $value;
        }

        $set[] = $meta['column'] . ' = :' . $apiField;
        $params[$apiField] = $value;
    }

    if ($set) {
        $sql = 'UPDATE bunkers SET ' . implode(', ', $set) . ' WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    return getBunkerById($pdo, $id);
}

function deleteBunkerById($pdo, $id)
{
    $stmt = $pdo->prepare('DELETE FROM bunkers WHERE id = :id');
    $stmt->execute(['id' => $id]);
    return $stmt->rowCount() > 0;
}

// --- Роуты ---

// GET /api/config
if ($route === 'config' && $method === 'GET') {
    jsonResponse([
        'yandexMapsApiKey' => $config['yandexMapsApiKey'] ?? '',
    ]);
}

// GET /api/auth — проверка авторизации
if ($route === 'auth' && $method === 'GET') {
    jsonResponse([
        'authenticated' => isAuthed($config),
        'user' => $_SESSION['user'] ?? null,
        'readonly' => !empty($_SESSION['readonly']),
    ]);
}

// POST /api/login — вход
if ($route === 'login' && $method === 'POST') {
    $body = getRequestBody();
    $login = trim($body['login'] ?? '');
    $pass = $body['password'] ?? '';

    if (empty($config['users'])) {
        jsonResponse(['error' => 'Авторизация не настроена'], 400);
    }

    if (!$login || !$pass) {
        jsonResponse(['error' => 'Укажите логин и пароль'], 400);
    }

    $hash = $config['users'][$login] ?? null;
    if (!$hash || !password_verify($pass, $hash)) {
        jsonResponse(['error' => 'Неверный логин или пароль'], 401);
    }

    $_SESSION['user'] = $login;
    $_SESSION['readonly'] = in_array($login, $config['readonlyUsers'] ?? [], true);
    jsonResponse(['user' => $login, 'readonly' => $_SESSION['readonly']]);
}

// POST /api/logout — выход
if ($route === 'logout' && $method === 'POST') {
    $_SESSION = [];
    session_destroy();
    jsonResponse(['success' => true]);
}

// /api/bunkers
if ($route === 'bunkers') {
    try {
        $pdo = getBunkersDb($legacyDataFile);
    } catch (Throwable $e) {
        logThrowable('mysql_connection_failed', $e, [
            'mysql' => [
                'host' => getenv('MYSQL_HOST') ?: 'localhost',
                'port' => getenv('MYSQL_PORT') ?: '3306',
                'user' => getenv('MYSQL_USER') ?: 'map_service',
                'database' => getenv('MYSQL_DATABASE') ?: 'map_service',
            ],
        ]);
        if (strpos($e->getMessage(), 'PDO MySQL driver is not installed') !== false || strpos($e->getMessage(), 'could not find driver') !== false) {
            jsonResponse(['error' => 'Не удалось подключиться к MySQL: в PHP не включен драйвер pdo_mysql'], 500);
        }
        jsonResponse(['error' => 'Не удалось подключиться к MySQL'], 500);
    }

    // GET /api/bunkers — список с фильтрацией
    if ($method === 'GET' && !$id) {
        try {
            $bunkers = listBunkers($pdo, [
                'district' => $_GET['district'] ?? '',
                'wasteType' => $_GET['wasteType'] ?? '',
                'contractor' => $_GET['contractor'] ?? '',
            ]);
            jsonResponse($bunkers);
        } catch (Throwable $e) {
            logThrowable('bunkers_get_failed', $e);
            jsonResponse(['error' => 'Не удалось загрузить бункеры'], 500);
        }
    }

    // POST /api/bunkers — создание
    if ($method === 'POST' && !$id) {
        requireWriteAuth($config);
        try {
            $body = getRequestBody();
            $newBunker = createBunker($pdo, $body);
            jsonResponse($newBunker, 201);
        } catch (Throwable $e) {
            logThrowable('bunkers_create_failed', $e);
            jsonResponse(['error' => 'Не удалось создать бункер'], 500);
        }
    }

    // PUT /api/bunkers/:id — обновление
    if ($method === 'PUT' && $id) {
        requireWriteAuth($config);
        try {
            $body = getRequestBody();
            $updated = updateBunker($pdo, $id, $body);

            if (!$updated) {
                jsonResponse(['error' => 'Бункер не найден'], 404);
            }

            jsonResponse($updated);
        } catch (Throwable $e) {
            logThrowable('bunkers_update_failed', $e, ['bunkerId' => $id]);
            jsonResponse(['error' => 'Не удалось обновить бункер'], 500);
        }
    }

    // DELETE /api/bunkers/:id — удаление
    if ($method === 'DELETE' && $id) {
        requireWriteAuth($config);
        try {
            $deleted = deleteBunkerById($pdo, $id);

            if (!$deleted) {
                jsonResponse(['error' => 'Бункер не найден'], 404);
            }

            jsonResponse(['success' => true]);
        } catch (Throwable $e) {
            logThrowable('bunkers_delete_failed', $e, ['bunkerId' => $id]);
            jsonResponse(['error' => 'Не удалось удалить бункер'], 500);
        }
    }
}

// Неизвестный маршрут
jsonResponse(['error' => 'Маршрут не найден'], 404);
