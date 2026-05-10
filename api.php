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

if (!defined('MAP_DEMO_DB_SESSION_KEY')) {
    define('MAP_DEMO_DB_SESSION_KEY', 'counterparty_uses_demo_database');
}

// --- Маршрутизация ---
$method = $_SERVER['REQUEST_METHOD'];
$route = isset($_GET['route']) ? trim($_GET['route'], '/') : '';
$id = null;
$idAction = null;
$rawId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
if ($rawId !== '') {
    $parts = explode('/', trim($rawId, '/'));
    $id = $parts[0] ?? null;
    $idAction = isset($parts[1]) ? trim((string) $parts[1]) : null;
}

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

function hasConfiguredUsers($config)
{
    if (!empty($config['users'])) {
        return true;
    }

    try {
        $pdo = getAuthDb();
        return countActiveCounterpartyUsers($pdo) > 0;
    } catch (Throwable $e) {
        logThrowable('counterparty_users_count_failed', $e);
        return false;
    }
}

function isAuthed($config)
{
    if (!hasConfiguredUsers($config)) {
        return true; // без пользователей — доступ без авторизации
    }
    return isset($_SESSION['user']);
}

function getCounterpartyAccessMap($config)
{
    $map = $config['counterpartyAccess'] ?? [];
    if (!is_array($map)) {
        return [];
    }

    $normalized = [];
    foreach ($map as $login => $counterpartyId) {
        $login = trim((string) $login);
        $counterpartyId = (int) $counterpartyId;
        if ($login === '' || $counterpartyId <= 0) {
            continue;
        }
        $normalized[$login] = $counterpartyId;
    }

    return $normalized;
}

function getSessionCounterpartyId()
{
    $counterpartyId = $_SESSION['counterparty_id'] ?? null;
    if ($counterpartyId === null || $counterpartyId === '') {
        return null;
    }

    $counterpartyId = (int) $counterpartyId;
    if ($counterpartyId <= 0) {
        return null;
    }

    return $counterpartyId;
}

function normalizeCaseInsensitiveValue($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value, 'UTF-8');
    }

    return strtolower($value);
}

function normalizeDistrictScopeTokens($rawScope)
{
    $parts = is_array($rawScope)
        ? $rawScope
        : preg_split('/[\r\n,;|]+/u', (string) $rawScope);

    if (!is_array($parts)) {
        return [];
    }

    $tokens = [];
    foreach ($parts as $token) {
        $token = normalizeCaseInsensitiveValue($token);
        if ($token === '') {
            continue;
        }
        $tokens[$token] = true;
    }

    return array_keys($tokens);
}

function getSessionDistrictScopeRaw()
{
    $value = $_SESSION['counterparty_district_scope'] ?? null;
    if ($value === null) {
        return null;
    }

    $value = trim((string) $value);

    return $value === '' ? null : $value;
}

function getSessionDistrictScopeTokens()
{
    return normalizeDistrictScopeTokens(getSessionDistrictScopeRaw());
}

function sessionUsesDemoDatabase()
{
    return !empty($_SESSION[MAP_DEMO_DB_SESSION_KEY]);
}

function setSessionDemoDatabase($usesDemoDatabase)
{
    $_SESSION[MAP_DEMO_DB_SESSION_KEY] = (bool) $usesDemoDatabase;
}

function clearSessionDemoDatabase()
{
    unset($_SESSION[MAP_DEMO_DB_SESSION_KEY]);
}

function districtMatchesScope($district, $scopeTokens)
{
    if (empty($scopeTokens)) {
        return true;
    }

    $districtNorm = normalizeCaseInsensitiveValue($district);
    if ($districtNorm === '') {
        return false;
    }

    foreach ($scopeTokens as $token) {
        if ($token !== '' && strpos($districtNorm, (string) $token) !== false) {
            return true;
        }
    }

    return false;
}

function getRequestApiToken()
{
    $xApiKeyCandidates = [
        $_SERVER['HTTP_X_API_KEY'] ?? '',
        $_SERVER['REDIRECT_HTTP_X_API_KEY'] ?? '',
    ];

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strcasecmp((string) $name, 'X-API-Key') === 0) {
                    $xApiKeyCandidates[] = $value;
                }
            }
        }
    }

    foreach ($xApiKeyCandidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    $authCandidates = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
    ];

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $authCandidates[] = $value;
                }
            }
        }
    }

    foreach ($authCandidates as $auth) {
        $auth = trim((string) $auth);
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return trim((string) $m[1]);
        }
    }

    return '';
}

function normalizeAllowedIps($rawIps)
{
    $parts = is_array($rawIps)
        ? $rawIps
        : preg_split('/[\s,;]+/', (string) $rawIps);

    if (!is_array($parts)) {
        return [];
    }

    $normalized = [];
    foreach ($parts as $ip) {
        $ip = trim((string) $ip);
        if ($ip === '') {
            continue;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            continue;
        }
        $normalized[$ip] = true;
    }

    return array_keys($normalized);
}

function isRequestIpAllowed($allowedIps)
{
    if (empty($allowedIps)) {
        return true;
    }

    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($ip === '') {
        return false;
    }

    return in_array($ip, $allowedIps, true);
}

function isMatchingApiToken($expectedToken)
{
    $expectedToken = trim((string) $expectedToken);
    if ($expectedToken === '') {
        return false;
    }

    $providedToken = getRequestApiToken();
    if ($providedToken === '') {
        return false;
    }

    return hash_equals($expectedToken, $providedToken);
}

/** Проверка API-ключа бота (X-API-Key или Authorization: Bearer) — для записи без сессии */
function isBotAuthed($config)
{
    if (!isMatchingApiToken($config['botApiKey'] ?? '')) {
        return false;
    }

    return isRequestIpAllowed(normalizeAllowedIps($config['botAllowedIps'] ?? []));
}

/** Проверка read-only API-ключа бота (X-API-Key или Authorization: Bearer) */
function isReadBotAuthed($config)
{
    if (isBotAuthed($config)) {
        return true; // ключ записи автоматически имеет доступ на чтение
    }

    if (!isMatchingApiToken($config['botReadApiKey'] ?? '')) {
        return false;
    }

    return isRequestIpAllowed(normalizeAllowedIps($config['botAllowedIps'] ?? []));
}

function requireAuth($config)
{
    if (!isAuthed($config)) {
        jsonResponse(['error' => 'Требуется авторизация'], 401);
    }
}

function requireReadAuth($config)
{
    if (isReadBotAuthed($config)) {
        return; // read-only бот с API-ключом
    }
    requireAuth($config);
}

function requireWriteAuth($config, $allowCounterpartyUser = false)
{
    if (isBotAuthed($config)) {
        return; // бот с API-ключом — пропускаем
    }
    requireAuth($config);
    if (!empty($_SESSION['readonly'])) {
        jsonResponse(['error' => 'Доступ только для чтения'], 403);
    }
    if (!$allowCounterpartyUser && getSessionCounterpartyId() !== null) {
        jsonResponse(['error' => 'Для этой учётной записи доступно только действие "Отметить заполненным"'], 403);
    }
}

function ensureCounterpartyCanAccessBunker($bunker, $counterpartyId, $districtScopeTokens = [])
{
    if ($counterpartyId === null) {
        return;
    }

    $bunkerCounterpartyId = array_key_exists('counterpartyId', $bunker)
        ? (int) ($bunker['counterpartyId'] ?? 0)
        : 0;

    if ($bunkerCounterpartyId <= 0 || $bunkerCounterpartyId !== (int) $counterpartyId) {
        jsonResponse(['error' => 'Нет доступа к этому бункеру'], 403);
    }

    $bunkerDistrict = (string) ($bunker['district'] ?? '');
    if (!districtMatchesScope($bunkerDistrict, $districtScopeTokens)) {
        jsonResponse(['error' => 'Нет доступа к этому району'], 403);
    }
}

function summarizeHttpBodyForLog($body, $limit = 300)
{
    $body = trim((string) $body);
    if ($body === '') {
        return '';
    }
    if (strlen($body) <= $limit) {
        return $body;
    }
    return substr($body, 0, $limit) . '...';
}

function buildMaxMarkFilledMessage($bunker, $filledBy)
{
    $number = (int) ($bunker['number'] ?? 0);
    $numberLabel = $number > 0 ? ('№' . $number) : 'б/н';
    $contractor = trim((string) ($bunker['contractor'] ?? ''));
    $address = trim((string) ($bunker['address'] ?? ''));
    $district = trim((string) ($bunker['district'] ?? ''));
    $actor = trim((string) $filledBy);
    $location = $district !== '' ? $district : ($address !== '' ? $address : '—');
    $bunkerLine = ($contractor !== '' ? '• ' . $contractor : '—') . ' — ' . $numberLabel . ', ' . $location;

    $lines = [
        '✅ Заявка принята: бункер заполнен',
        '',
        $bunkerLine,
        '',
        'Отметил: ' . ($actor !== '' ? $actor : '—'),
        'Время: ' . date('d.m.Y H:i'),
    ];

    return implode("\n", $lines);
}

function sendMaxChatMessage($config, $text)
{
    $token = trim((string) ($config['maxBotToken'] ?? ''));
    $chatIdRaw = trim((string) ($config['maxRequestChatId'] ?? ''));

    if ($token === '' || $chatIdRaw === '' || !preg_match('/^-?\d+$/', $chatIdRaw) || $chatIdRaw === '0') {
        return ['enabled' => false, 'sent' => false];
    }

    $url = 'https://platform-api.max.ru/messages?chat_id=' . rawurlencode($chatIdRaw);
    $payload = json_encode(
        ['text' => (string) $text, 'notify' => true],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($payload === false) {
        writeAppLog('warning', 'max_message_encode_failed');
        return ['enabled' => true, 'sent' => false];
    }

    $statusCode = 0;
    $responseBody = '';

    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Authorization: ' . $token,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => $payload,
            ]);

            $response = curl_exec($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                writeAppLog('warning', 'max_message_send_failed', ['error' => $curlError]);
                return ['enabled' => true, 'sent' => false];
            }
            $responseBody = (string) $response;
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Authorization: {$token}\r\nContent-Type: application/json\r\n",
                    'content' => $payload,
                    'timeout' => 10,
                    'ignore_errors' => true,
                ],
            ]);

            $response = @file_get_contents($url, false, $context);
            $responseBody = $response !== false ? (string) $response : '';

            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
                $statusCode = (int) $m[1];
            }

            if ($response === false && $statusCode === 0) {
                writeAppLog('warning', 'max_message_send_failed', ['error' => 'file_get_contents_failed']);
                return ['enabled' => true, 'sent' => false];
            }
        }
    } catch (Throwable $e) {
        logThrowable('max_message_send_failed_exception', $e);
        return ['enabled' => true, 'sent' => false];
    }

    $sent = $statusCode >= 200 && $statusCode < 300;
    if (!$sent) {
        writeAppLog('warning', 'max_message_send_http_failed', [
            'statusCode' => $statusCode,
            'responseBody' => summarizeHttpBodyForLog($responseBody),
        ]);
    }

    return ['enabled' => true, 'sent' => $sent, 'statusCode' => $statusCode];
}

function getMysqlEnv($name, $fallback = '', $allowEmpty = false)
{
    $value = getenv($name);
    if ($value === false || (!$allowEmpty && $value === '')) {
        return $fallback;
    }

    return $value;
}

function getMysqlConnectionConfig($mode)
{
    if ($mode === 'demo') {
        $database = trim((string) getMysqlEnv('DEMO_DB_DATABASE', ''));
        if ($database === '') {
            throw new RuntimeException('Demo database connection is not configured');
        }

        return [
            'host' => getMysqlEnv('DEMO_DB_HOST', getMysqlEnv('MYSQL_HOST', 'localhost')),
            'port' => getMysqlEnv('DEMO_DB_PORT', getMysqlEnv('MYSQL_PORT', '3306')),
            'user' => getMysqlEnv('DEMO_DB_USERNAME', getMysqlEnv('DEMO_DB_USER', getMysqlEnv('MYSQL_USER', 'map_service'))),
            'password' => getenv('DEMO_DB_PASSWORD') === false
                ? getMysqlEnv('MYSQL_PASSWORD', '', true)
                : getMysqlEnv('DEMO_DB_PASSWORD', '', true),
            'database' => $database,
            'charset' => getMysqlEnv('DEMO_DB_CHARSET', 'utf8mb4'),
        ];
    }

    return [
        'host' => getMysqlEnv('MYSQL_HOST', 'localhost'),
        'port' => getMysqlEnv('MYSQL_PORT', '3306'),
        'user' => getMysqlEnv('MYSQL_USER', 'map_service'),
        'password' => getMysqlEnv('MYSQL_PASSWORD', '', true),
        'database' => getMysqlEnv('MYSQL_DATABASE', 'map_service'),
        'charset' => 'utf8mb4',
    ];
}

function getCurrentMysqlConnectionMode()
{
    return sessionUsesDemoDatabase() ? 'demo' : 'default';
}

function getMysqlConnection($mode = null)
{
    static $connections = [];

    $mode = $mode ?: getCurrentMysqlConnectionMode();
    if (isset($connections[$mode]) && $connections[$mode] instanceof PDO) {
        return $connections[$mode];
    }

    if (!class_exists('PDO')) {
        throw new RuntimeException('PDO extension is not available');
    }
    if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('PDO MySQL driver is not installed');
    }

    $connectionConfig = getMysqlConnectionConfig($mode);
    $host = $connectionConfig['host'];
    $port = $connectionConfig['port'];
    $user = $connectionConfig['user'];
    $password = $connectionConfig['password'];
    $database = $connectionConfig['database'];
    $charset = $connectionConfig['charset'] ?: 'utf8mb4';

    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
    $connections[$mode] = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $connections[$mode];
}

function ensureCounterpartyUsersTable($pdo)
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS counterparty_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    login VARCHAR(191) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    counterparty_id INT UNSIGNED NOT NULL,
    district_scope VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_counterparty_users_login (login),
    KEY idx_counterparty_users_counterparty_id (counterparty_id),
    KEY idx_counterparty_users_district_scope (district_scope),
    KEY idx_counterparty_users_is_active (is_active),
    KEY idx_counterparty_users_is_demo (is_demo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    $pdo->exec($sql);

    if (!columnExists($pdo, 'counterparty_users', 'district_scope')) {
        $pdo->exec('ALTER TABLE counterparty_users ADD COLUMN district_scope VARCHAR(255) NULL AFTER counterparty_id');
    }

    if (!indexExists($pdo, 'counterparty_users', 'idx_counterparty_users_district_scope')) {
        $pdo->exec('ALTER TABLE counterparty_users ADD INDEX idx_counterparty_users_district_scope (district_scope)');
    }

    if (!columnExists($pdo, 'counterparty_users', 'is_demo')) {
        $pdo->exec('ALTER TABLE counterparty_users ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active');
    }

    if (!indexExists($pdo, 'counterparty_users', 'idx_counterparty_users_is_demo')) {
        $pdo->exec('ALTER TABLE counterparty_users ADD INDEX idx_counterparty_users_is_demo (is_demo)');
    }

    $ensured = true;
}

function getAuthDb()
{
    $pdo = getMysqlConnection('default');
    ensureCounterpartyUsersTable($pdo);

    return $pdo;
}

function countActiveCounterpartyUsers($pdo)
{
    $stmt = $pdo->query('SELECT COUNT(*) FROM counterparty_users WHERE is_active = 1');
    return (int) $stmt->fetchColumn();
}

function findActiveCounterpartyUserByLogin($pdo, $login)
{
    $login = trim((string) $login);
    if ($login === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, login, password_hash, counterparty_id, district_scope, is_demo
         FROM counterparty_users
         WHERE login = :login AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute(['login' => $login]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'login' => (string) ($row['login'] ?? ''),
        'password_hash' => (string) ($row['password_hash'] ?? ''),
        'counterparty_id' => (int) ($row['counterparty_id'] ?? 0),
        'district_scope' => array_key_exists('district_scope', $row) && $row['district_scope'] !== null
            ? trim((string) $row['district_scope'])
            : null,
        'is_demo' => !empty($row['is_demo']),
    ];
}

function initBunkersTable($pdo)
{
    static $initialized = [];
    $key = spl_object_id($pdo);

    if (!empty($initialized[$key])) {
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
    counterparty_id INT NULL,
    waste_type VARCHAR(100) NOT NULL DEFAULT 'КГО',
    last_pickup_date VARCHAR(10) NOT NULL DEFAULT '',
    fill_level INT NOT NULL DEFAULT 0,
    last_filled_at DATETIME NULL,
    last_filled_by VARCHAR(255) NULL,
    contact_phone VARCHAR(50) NOT NULL DEFAULT '',
    lat DECIMAL(17,14) NOT NULL DEFAULT 0,
    lng DECIMAL(17,14) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bunkers_number (`number`),
    KEY idx_bunkers_district (district),
    KEY idx_bunkers_waste_type (waste_type),
    KEY idx_bunkers_contractor (contractor),
    KEY idx_bunkers_counterparty_id (counterparty_id),
    KEY idx_bunkers_counterparty_number (counterparty_id, `number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    $pdo->exec($sql);
    $initialized[$key] = true;
}

function initBunkerFillRequestsTable($pdo)
{
    static $initialized = [];
    $key = spl_object_id($pdo);

    if (!empty($initialized[$key])) {
        return;
    }

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS bunker_fill_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bunker_id VARCHAR(64) NOT NULL,
    bunker_number INT NOT NULL DEFAULT 0,
    counterparty_id INT NULL,
    contractor VARCHAR(255) NOT NULL DEFAULT '',
    district VARCHAR(255) NOT NULL DEFAULT '',
    address VARCHAR(255) NOT NULL DEFAULT '',
    waste_type VARCHAR(100) NOT NULL DEFAULT 'КГО',
    fill_level INT NOT NULL DEFAULT 100,
    filled_by VARCHAR(255) NOT NULL DEFAULT '',
    filled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    executed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bunker_fill_requests_bunker_id (bunker_id),
    KEY idx_bunker_fill_requests_filled_at (filled_at),
    KEY idx_bunker_fill_requests_executed_at (executed_at),
    KEY idx_bunker_fill_requests_counterparty_id (counterparty_id),
    KEY idx_bunker_fill_requests_bunker_filled_at (bunker_id, filled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    $pdo->exec($sql);
    $initialized[$key] = true;
}

function tableExists($pdo, $tableName)
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = :tableName'
    );
    $stmt->execute(['tableName' => $tableName]);
    return (int) $stmt->fetchColumn() > 0;
}

function columnExists($pdo, $tableName, $columnName)
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :tableName
           AND column_name = :columnName'
    );
    $stmt->execute([
        'tableName' => $tableName,
        'columnName' => $columnName,
    ]);
    return (int) $stmt->fetchColumn() > 0;
}

function indexExists($pdo, $tableName, $indexName)
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :tableName
           AND index_name = :indexName'
    );
    $stmt->execute([
        'tableName' => $tableName,
        'indexName' => $indexName,
    ]);
    return (int) $stmt->fetchColumn() > 0;
}

function hasCounterpartyForeignKey($pdo)
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.key_column_usage
         WHERE table_schema = DATABASE()
           AND table_name = :tableName
           AND column_name = :columnName
           AND referenced_table_name = :referencedTable'
    );
    $stmt->execute([
        'tableName' => 'bunkers',
        'columnName' => 'counterparty_id',
        'referencedTable' => 'counterparties',
    ]);
    return (int) $stmt->fetchColumn() > 0;
}

function counterpartiesTableExists($pdo)
{
    static $exists = [];
    $key = spl_object_id($pdo);
    if (array_key_exists($key, $exists)) {
        return $exists[$key];
    }
    $exists[$key] = tableExists($pdo, 'counterparties');
    return $exists[$key];
}

function findCounterpartyIdByShortName($pdo, $shortName)
{
    if (!counterpartiesTableExists($pdo)) {
        return null;
    }

    $shortName = trim((string) $shortName);
    if ($shortName === '') {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id FROM counterparties WHERE short_name = :shortName LIMIT 1');
    $stmt->execute(['shortName' => $shortName]);
    $id = $stmt->fetchColumn();

    if ($id === false) {
        return null;
    }

    return (int) $id;
}

function findCounterpartyShortNameById($pdo, $counterpartyId)
{
    if (!counterpartiesTableExists($pdo)) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT short_name FROM counterparties WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int) $counterpartyId]);
    $shortName = $stmt->fetchColumn();

    if ($shortName === false) {
        return null;
    }

    return (string) $shortName;
}

function ensureBunkersCounterpartyRelation($pdo)
{
    static $ensured = [];
    $key = spl_object_id($pdo);

    if (!empty($ensured[$key])) {
        return;
    }

    if (!columnExists($pdo, 'bunkers', 'counterparty_id')) {
        $pdo->exec('ALTER TABLE bunkers ADD COLUMN counterparty_id INT NULL AFTER contractor');
    }

    if (!indexExists($pdo, 'bunkers', 'idx_bunkers_counterparty_id')) {
        $pdo->exec('ALTER TABLE bunkers ADD INDEX idx_bunkers_counterparty_id (counterparty_id)');
    }
    if (!indexExists($pdo, 'bunkers', 'idx_bunkers_counterparty_number')) {
        $pdo->exec('ALTER TABLE bunkers ADD INDEX idx_bunkers_counterparty_number (counterparty_id, `number`)');
    }

    if (!counterpartiesTableExists($pdo)) {
        writeAppLog('warning', 'counterparties_table_missing_for_bunkers_relation');
        $ensured[$key] = true;
        return;
    }

    // Автопривязка существующих строк из старого contractor -> counterparties.short_name
    $pdo->exec(
        'UPDATE bunkers b
         JOIN counterparties c ON c.short_name = b.contractor
         SET b.counterparty_id = c.id
         WHERE b.counterparty_id IS NULL
           AND b.contractor <> \'\''
    );

    if (!hasCounterpartyForeignKey($pdo)) {
        $orphans = (int) $pdo->query(
            'SELECT COUNT(*)
             FROM bunkers b
             LEFT JOIN counterparties c ON c.id = b.counterparty_id
             WHERE b.counterparty_id IS NOT NULL
               AND c.id IS NULL'
        )->fetchColumn();

        if ($orphans === 0) {
            $pdo->exec(
                'ALTER TABLE bunkers
                 ADD CONSTRAINT fk_bunkers_counterparty_id
                 FOREIGN KEY (counterparty_id) REFERENCES counterparties(id)
                 ON DELETE SET NULL
                 ON UPDATE CASCADE'
            );
        } else {
            writeAppLog('warning', 'bunkers_counterparty_fk_skipped_orphans', ['orphans' => $orphans]);
        }
    }

    $ensured[$key] = true;
}

function ensureBunkersFillMarkColumns($pdo)
{
    static $ensured = [];
    $key = spl_object_id($pdo);

    if (!empty($ensured[$key])) {
        return;
    }

    if (!columnExists($pdo, 'bunkers', 'last_filled_at')) {
        $pdo->exec('ALTER TABLE bunkers ADD COLUMN last_filled_at DATETIME NULL AFTER fill_level');
    }

    if (!columnExists($pdo, 'bunkers', 'last_filled_by')) {
        $pdo->exec('ALTER TABLE bunkers ADD COLUMN last_filled_by VARCHAR(255) NULL AFTER last_filled_at');
    }

    $ensured[$key] = true;
}

function ensureBunkerFillRequestsExecutionColumn($pdo)
{
    static $ensured = [];
    $key = spl_object_id($pdo);

    if (!empty($ensured[$key])) {
        return;
    }

    if (!columnExists($pdo, 'bunker_fill_requests', 'executed_at')) {
        $pdo->exec('ALTER TABLE bunker_fill_requests ADD COLUMN executed_at DATETIME NULL AFTER filled_at');
    }

    if (!indexExists($pdo, 'bunker_fill_requests', 'idx_bunker_fill_requests_executed_at')) {
        $pdo->exec('ALTER TABLE bunker_fill_requests ADD INDEX idx_bunker_fill_requests_executed_at (executed_at)');
    }

    $ensured[$key] = true;
}

function migrateLegacyJsonIfNeeded($pdo, $legacyFile)
{
    static $migrated = [];
    $key = spl_object_id($pdo);

    if (!empty($migrated[$key])) {
        return;
    }

    $rowsCount = (int) $pdo->query('SELECT COUNT(*) FROM bunkers')->fetchColumn();
    if ($rowsCount > 0 || !file_exists($legacyFile)) {
        $migrated[$key] = true;
        return;
    }

    $legacyBunkers = readLegacyBunkers($legacyFile);
    if (!$legacyBunkers) {
        $migrated[$key] = true;
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

    $migrated[$key] = true;
}

function mapBunkerRow($row)
{
    $filledAtRaw = $row['filledAt'] ?? null;
    $filledAt = null;
    if ($filledAtRaw !== null && $filledAtRaw !== '') {
        try {
            $filledAt = (new DateTime((string) $filledAtRaw))->format(DATE_ATOM);
        } catch (Throwable $e) {
            $filledAt = (string) $filledAtRaw;
        }
    }

    return [
        'id' => (string) $row['id'],
        'number' => (int) $row['number'],
        'volume' => (float) $row['volume'],
        'address' => normalizeAddress($row['address'] ?? ''),
        'district' => (string) ($row['district'] ?? ''),
        'contractor' => (string) ($row['contractor'] ?? ''),
        'counterpartyId' => array_key_exists('counterpartyId', $row) && $row['counterpartyId'] !== null
            ? (int) $row['counterpartyId']
            : null,
        'wasteType' => (string) ($row['wasteType'] ?? 'КГО'),
        'lastPickupDate' => (string) ($row['lastPickupDate'] ?? ''),
        'fillLevel' => (int) ($row['fillLevel'] ?? 0),
        'filledAt' => $filledAt,
        'filledBy' => array_key_exists('filledBy', $row) && $row['filledBy'] !== null && $row['filledBy'] !== ''
            ? (string) $row['filledBy']
            : null,
        'contactPhone' => (string) ($row['contactPhone'] ?? ''),
        'lat' => (float) ($row['lat'] ?? 0),
        'lng' => (float) ($row['lng'] ?? 0),
    ];
}

function getBunkersDb($legacyFile)
{
    static $ready = [];
    $pdo = getMysqlConnection();
    $key = spl_object_id($pdo);

    if (empty($ready[$key])) {
        initBunkersTable($pdo);
        initBunkerFillRequestsTable($pdo);
        migrateLegacyJsonIfNeeded($pdo, $legacyFile);
        ensureBunkersCounterpartyRelation($pdo);
        ensureBunkersFillMarkColumns($pdo);
        ensureBunkerFillRequestsExecutionColumn($pdo);
        $ready[$key] = true;
    }

    return $pdo;
}

function listBunkers($pdo, $filters = [])
{
    $hasCounterparties = counterpartiesTableExists($pdo);
    if ($hasCounterparties) {
        $sql = 'SELECT b.id, b.`number`, b.volume, b.address, b.district,
                       COALESCE(c.short_name, b.contractor, \'\') AS contractor,
                       b.counterparty_id AS counterpartyId,
                       b.waste_type AS wasteType, b.last_pickup_date AS lastPickupDate, b.fill_level AS fillLevel,
                       b.last_filled_at AS filledAt, b.last_filled_by AS filledBy,
                       b.contact_phone AS contactPhone, b.lat, b.lng
                FROM bunkers b
                LEFT JOIN counterparties c ON c.id = b.counterparty_id';
    } else {
        $sql = 'SELECT b.id, b.`number`, b.volume, b.address, b.district,
                       b.contractor AS contractor,
                       b.counterparty_id AS counterpartyId,
                       b.waste_type AS wasteType, b.last_pickup_date AS lastPickupDate, b.fill_level AS fillLevel,
                       b.last_filled_at AS filledAt, b.last_filled_by AS filledBy,
                       b.contact_phone AS contactPhone, b.lat, b.lng
                FROM bunkers b';
    }

    $where = [];
    $params = [];

    if (!empty($filters['district'])) {
        $where[] = 'b.district = :district';
        $params['district'] = $filters['district'];
    }
    if (!empty($filters['wasteType'])) {
        $where[] = 'b.waste_type = :wasteType';
        $params['wasteType'] = $filters['wasteType'];
    }
    if (!empty($filters['contractor'])) {
        if ($hasCounterparties) {
            $where[] = 'COALESCE(c.short_name, b.contractor, \'\') = :contractor';
        } else {
            $where[] = 'b.contractor = :contractor';
        }
        $params['contractor'] = $filters['contractor'];
    }
    if (array_key_exists('counterpartyId', $filters) && $filters['counterpartyId'] !== '' && $filters['counterpartyId'] !== null) {
        $where[] = 'b.counterparty_id = :counterpartyId';
        $params['counterpartyId'] = (int) $filters['counterpartyId'];
    }
    if (!empty($filters['districtScopeTokens']) && is_array($filters['districtScopeTokens'])) {
        $scopeWhere = [];
        foreach (array_values($filters['districtScopeTokens']) as $index => $token) {
            $token = normalizeCaseInsensitiveValue($token);
            if ($token === '') {
                continue;
            }

            $paramName = 'districtScope' . $index;
            $scopeWhere[] = 'LOWER(b.district) LIKE :' . $paramName;
            $params[$paramName] = '%' . $token . '%';
        }

        if ($scopeWhere) {
            $where[] = '(' . implode(' OR ', $scopeWhere) . ')';
        }
    }

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY b.counterparty_id ASC, b.`number` ASC, b.id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    return array_map('mapBunkerRow', $rows);
}

function getBunkerById($pdo, $id)
{
    $hasCounterparties = counterpartiesTableExists($pdo);
    if ($hasCounterparties) {
        $stmt = $pdo->prepare(
            'SELECT b.id, b.`number`, b.volume, b.address, b.district,
                    COALESCE(c.short_name, b.contractor, \'\') AS contractor,
                    b.counterparty_id AS counterpartyId,
                    b.waste_type AS wasteType, b.last_pickup_date AS lastPickupDate, b.fill_level AS fillLevel,
                    b.last_filled_at AS filledAt, b.last_filled_by AS filledBy,
                    b.contact_phone AS contactPhone, b.lat, b.lng
             FROM bunkers b
             LEFT JOIN counterparties c ON c.id = b.counterparty_id
             WHERE b.id = :id'
        );
    } else {
        $stmt = $pdo->prepare(
            'SELECT b.id, b.`number`, b.volume, b.address, b.district,
                    b.contractor AS contractor,
                    b.counterparty_id AS counterpartyId,
                    b.waste_type AS wasteType, b.last_pickup_date AS lastPickupDate, b.fill_level AS fillLevel,
                    b.last_filled_at AS filledAt, b.last_filled_by AS filledBy,
                    b.contact_phone AS contactPhone, b.lat, b.lng
             FROM bunkers b
             WHERE b.id = :id'
        );
    }
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    return mapBunkerRow($row);
}

function listCounterparties($pdo)
{
    if (!counterpartiesTableExists($pdo)) {
        return [];
    }

    $hasSchedule = columnExists($pdo, 'counterparties', 'invoice_schedule');
    $hasOperationType = columnExists($pdo, 'counterparties', 'operation_type');
    $hasStatus = columnExists($pdo, 'counterparties', 'status');

    $select = [
        'id',
        'short_name AS shortName',
        'name',
        $hasSchedule ? 'invoice_schedule AS schedule' : 'NULL AS schedule',
        $hasOperationType ? 'operation_type' : 'NULL AS operation_type',
    ];

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM counterparties';
    if ($hasStatus) {
        $sql .= ' WHERE status = \'active\'';
    }
    $sql .= ' ORDER BY short_name ASC';

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();

    return array_map(function ($row) {
        return [
            'id' => (int) $row['id'],
            'shortName' => (string) ($row['shortName'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'schedule' => array_key_exists('schedule', $row) && $row['schedule'] !== null
                ? (string) $row['schedule']
                : null,
            'operation_type' => array_key_exists('operation_type', $row) && $row['operation_type'] !== null
                ? (string) $row['operation_type']
                : null,
        ];
    }, $rows);
}

function createBunker($pdo, $body)
{
    $nextNumber = (int) $pdo->query('SELECT COUNT(*) FROM bunkers')->fetchColumn() + 1;
    $contractor = trim((string) ($body['contractor'] ?? ''));
    $counterpartyId = null;

    if (array_key_exists('counterpartyId', $body) && $body['counterpartyId'] !== '' && $body['counterpartyId'] !== null) {
        if (!counterpartiesTableExists($pdo)) {
            throw new InvalidArgumentException('Таблица counterparties не найдена');
        }
        $counterpartyId = (int) $body['counterpartyId'];
        if ($counterpartyId <= 0) {
            throw new InvalidArgumentException('Некорректный counterpartyId');
        }
        $shortName = findCounterpartyShortNameById($pdo, $counterpartyId);
        if ($shortName === null) {
            throw new InvalidArgumentException('Контрагент не найден');
        }
        $contractor = $shortName;
    } elseif ($contractor !== '') {
        $resolvedCounterpartyId = findCounterpartyIdByShortName($pdo, $contractor);
        if ($resolvedCounterpartyId !== null) {
            $counterpartyId = $resolvedCounterpartyId;
            $resolvedShortName = findCounterpartyShortNameById($pdo, $resolvedCounterpartyId);
            if ($resolvedShortName !== null) {
                $contractor = $resolvedShortName;
            }
        }
    }

    $newBunker = [
        'id' => generateId(),
        'number' => array_key_exists('number', $body) ? (int) $body['number'] : $nextNumber,
        'volume' => array_key_exists('volume', $body) ? (float) $body['volume'] : 8,
        'address' => normalizeAddress($body['address'] ?? ''),
        'district' => (string) ($body['district'] ?? ''),
        'contractor' => $contractor,
        'counterpartyId' => $counterpartyId,
        'wasteType' => (string) ($body['wasteType'] ?? 'КГО'),
        'lastPickupDate' => (string) ($body['lastPickupDate'] ?? date('Y-m-d')),
        'fillLevel' => array_key_exists('fillLevel', $body) ? (int) $body['fillLevel'] : 0,
        'contactPhone' => (string) ($body['contactPhone'] ?? ''),
        'lat' => array_key_exists('lat', $body) ? (float) $body['lat'] : 0,
        'lng' => array_key_exists('lng', $body) ? (float) $body['lng'] : 0,
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO bunkers (id, `number`, volume, address, district, contractor, counterparty_id, waste_type, last_pickup_date, fill_level, contact_phone, lat, lng)
         VALUES (:id, :number, :volume, :address, :district, :contractor, :counterpartyId, :wasteType, :lastPickupDate, :fillLevel, :contactPhone, :lat, :lng)'
    );

    $stmt->execute([
        'id' => $newBunker['id'],
        'number' => $newBunker['number'],
        'volume' => $newBunker['volume'],
        'address' => $newBunker['address'],
        'district' => $newBunker['district'],
        'contractor' => $newBunker['contractor'],
        'counterpartyId' => $newBunker['counterpartyId'],
        'wasteType' => $newBunker['wasteType'],
        'lastPickupDate' => $newBunker['lastPickupDate'],
        'fillLevel' => $newBunker['fillLevel'],
        'contactPhone' => $newBunker['contactPhone'],
        'lat' => $newBunker['lat'],
        'lng' => $newBunker['lng'],
    ]);

    $created = getBunkerById($pdo, $newBunker['id']);
    return $created ?: $newBunker;
}

function updateBunker($pdo, $id, $body)
{
    $fieldMap = [
        'number' => ['column' => '`number`', 'type' => 'int'],
        'volume' => ['column' => 'volume', 'type' => 'float'],
        'address' => ['column' => 'address', 'type' => 'address'],
        'district' => ['column' => 'district', 'type' => 'string'],
        'wasteType' => ['column' => 'waste_type', 'type' => 'string'],
        'lastPickupDate' => ['column' => 'last_pickup_date', 'type' => 'string'],
        'fillLevel' => ['column' => 'fill_level', 'type' => 'int'],
        'contactPhone' => ['column' => 'contact_phone', 'type' => 'string'],
        'lat' => ['column' => 'lat', 'type' => 'float'],
        'lng' => ['column' => 'lng', 'type' => 'float'],
    ];

    $set = [];
    $params = ['id' => $id];
    $shouldMarkFillRequestExecuted = array_key_exists('fillLevel', $body) && (int) $body['fillLevel'] === 0;

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

    $hasCounterpartyId = array_key_exists('counterpartyId', $body);
    $hasContractor = array_key_exists('contractor', $body);

    if ($hasCounterpartyId) {
        $rawCounterpartyId = $body['counterpartyId'];
        if ($rawCounterpartyId === '' || $rawCounterpartyId === null) {
            $set[] = 'counterparty_id = NULL';
            if ($hasContractor) {
                $contractorValue = trim((string) $body['contractor']);
                $set[] = 'contractor = :contractor';
                $params['contractor'] = $contractorValue;
            }
        } else {
            if (!counterpartiesTableExists($pdo)) {
                throw new InvalidArgumentException('Таблица counterparties не найдена');
            }
            $counterpartyId = (int) $rawCounterpartyId;
            if ($counterpartyId <= 0) {
                throw new InvalidArgumentException('Некорректный counterpartyId');
            }
            $shortName = findCounterpartyShortNameById($pdo, $counterpartyId);
            if ($shortName === null) {
                throw new InvalidArgumentException('Контрагент не найден');
            }
            $set[] = 'counterparty_id = :counterpartyId';
            $params['counterpartyId'] = $counterpartyId;
            $set[] = 'contractor = :contractor';
            $params['contractor'] = $shortName;
        }
    } elseif ($hasContractor) {
        $contractorValue = trim((string) $body['contractor']);
        $set[] = 'contractor = :contractor';
        $params['contractor'] = $contractorValue;

        if ($contractorValue === '') {
            $set[] = 'counterparty_id = NULL';
        } else {
            $resolvedCounterpartyId = findCounterpartyIdByShortName($pdo, $contractorValue);
            if ($resolvedCounterpartyId !== null) {
                $resolvedShortName = findCounterpartyShortNameById($pdo, $resolvedCounterpartyId);
                $set[] = 'counterparty_id = :resolvedCounterpartyId';
                $params['resolvedCounterpartyId'] = $resolvedCounterpartyId;
                if ($resolvedShortName !== null) {
                    $params['contractor'] = $resolvedShortName;
                }
            } else {
                $set[] = 'counterparty_id = NULL';
            }
        }
    }

    if ($set) {
        $sql = 'UPDATE bunkers SET ' . implode(', ', $set) . ' WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    $updated = getBunkerById($pdo, $id);

    if ($updated && $shouldMarkFillRequestExecuted) {
        markLatestBunkerFillRequestExecuted($pdo, $id);
    }

    return $updated;
}

function markLatestBunkerFillRequestExecuted($pdo, $bunkerId)
{
    $stmt = $pdo->prepare(
        'SELECT id
         FROM bunker_fill_requests
         WHERE bunker_id = :bunkerId
           AND executed_at IS NULL
         ORDER BY filled_at DESC, id DESC
         LIMIT 1'
    );
    $stmt->execute(['bunkerId' => (string) $bunkerId]);
    $requestId = $stmt->fetchColumn();

    if ($requestId === false) {
        return 0;
    }

    $updateStmt = $pdo->prepare(
        'UPDATE bunker_fill_requests
         SET executed_at = NOW()
         WHERE id = :id
           AND executed_at IS NULL'
    );
    $updateStmt->execute(['id' => (int) $requestId]);

    return $updateStmt->rowCount();
}

function markBunkerFilled($pdo, $id, $filledBy, $fillLevel = 100)
{
    $fillLevel = (int) $fillLevel;
    if ($fillLevel < 0) {
        $fillLevel = 0;
    } elseif ($fillLevel > 100) {
        $fillLevel = 100;
    }

    try {
        $pdo->beginTransaction();

        $filledAtRaw = $pdo->query('SELECT NOW()')->fetchColumn();
        $filledAt = is_string($filledAtRaw) && $filledAtRaw !== ''
            ? $filledAtRaw
            : date('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            'UPDATE bunkers
             SET fill_level = :fillLevel,
                 last_filled_at = :filledAt,
                 last_filled_by = :filledBy
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'fillLevel' => $fillLevel,
            'filledAt' => $filledAt,
            'filledBy' => (string) $filledBy,
        ]);

        $updated = getBunkerById($pdo, $id);
        if (!$updated) {
            $pdo->rollBack();
            return null;
        }

        $historyStmt = $pdo->prepare(
            'INSERT INTO bunker_fill_requests
             (bunker_id, bunker_number, counterparty_id, contractor, district, address, waste_type, fill_level, filled_by, filled_at)
             VALUES
             (:bunkerId, :bunkerNumber, :counterpartyId, :contractor, :district, :address, :wasteType, :fillLevel, :filledBy, :filledAt)'
        );
        $historyStmt->execute([
            'bunkerId' => (string) ($updated['id'] ?? $id),
            'bunkerNumber' => (int) ($updated['number'] ?? 0),
            'counterpartyId' => array_key_exists('counterpartyId', $updated) && $updated['counterpartyId'] !== null
                ? (int) $updated['counterpartyId']
                : null,
            'contractor' => (string) ($updated['contractor'] ?? ''),
            'district' => (string) ($updated['district'] ?? ''),
            'address' => normalizeAddress($updated['address'] ?? ''),
            'wasteType' => (string) ($updated['wasteType'] ?? 'КГО'),
            'fillLevel' => $fillLevel,
            'filledBy' => (string) $filledBy,
            'filledAt' => $filledAt,
        ]);

        $pdo->commit();
        return $updated;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
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
    $counterpartyId = getSessionCounterpartyId();
    $districtScope = getSessionDistrictScopeRaw();
    jsonResponse([
        'authenticated' => isAuthed($config),
        'user' => $_SESSION['user'] ?? null,
        'readonly' => !empty($_SESSION['readonly']),
        'counterpartyId' => $counterpartyId,
        'districtScope' => $districtScope,
        'isCounterpartyUser' => $counterpartyId !== null,
        'usesDemoDatabase' => sessionUsesDemoDatabase(),
    ]);
}

// POST /api/login — вход
if ($route === 'login' && $method === 'POST') {
    $body = getRequestBody();
    $login = trim($body['login'] ?? '');
    $pass = $body['password'] ?? '';

    if (!hasConfiguredUsers($config)) {
        jsonResponse(['error' => 'Авторизация не настроена'], 400);
    }

    if (!$login || !$pass) {
        jsonResponse(['error' => 'Укажите логин и пароль'], 400);
    }

    $counterpartyId = null;
    $counterpartyUserId = null;
    $districtScope = null;
    $readonly = false;
    $usesDemoDatabase = false;
    $hash = $config['users'][$login] ?? null;

    if ($hash !== null) {
        if (!password_verify($pass, $hash)) {
            jsonResponse(['error' => 'Неверный логин или пароль'], 401);
        }

        $readonly = in_array($login, $config['readonlyUsers'] ?? [], true);
        $counterpartyAccess = getCounterpartyAccessMap($config);
        $counterpartyId = array_key_exists($login, $counterpartyAccess)
            ? (int) $counterpartyAccess[$login]
            : null;
    } else {
        try {
            $counterpartyUser = findActiveCounterpartyUserByLogin(getAuthDb(), $login);
        } catch (Throwable $e) {
            logThrowable('counterparty_user_auth_failed', $e, ['login' => $login]);
            jsonResponse(['error' => 'Не удалось выполнить авторизацию'], 500);
        }

        $passwordHash = (string) ($counterpartyUser['password_hash'] ?? '');
        if (!$counterpartyUser || $passwordHash === '' || !password_verify($pass, $passwordHash)) {
            jsonResponse(['error' => 'Неверный логин или пароль'], 401);
        }

        $counterpartyId = (int) ($counterpartyUser['counterparty_id'] ?? 0);
        if ($counterpartyId <= 0) {
            jsonResponse(['error' => 'Учётная запись не привязана к контрагенту'], 403);
        }

        $counterpartyUserId = (int) ($counterpartyUser['id'] ?? 0);

        $districtScope = array_key_exists('district_scope', $counterpartyUser)
            ? trim((string) ($counterpartyUser['district_scope'] ?? ''))
            : '';

        if ($districtScope === '') {
            $districtScope = null;
        }

        $usesDemoDatabase = !empty($counterpartyUser['is_demo']);
    }

    $_SESSION['user'] = $login;
    $_SESSION['readonly'] = $readonly;
    setSessionDemoDatabase($usesDemoDatabase);

    if ($counterpartyId !== null && $counterpartyId > 0) {
        $_SESSION['counterparty_id'] = $counterpartyId;
        if ($counterpartyUserId !== null && $counterpartyUserId > 0) {
            $_SESSION['counterparty_user_id'] = $counterpartyUserId;
        } else {
            unset($_SESSION['counterparty_user_id']);
        }

        if ($districtScope !== null) {
            $_SESSION['counterparty_district_scope'] = $districtScope;
        } else {
            unset($_SESSION['counterparty_district_scope']);
        }
    } else {
        unset($_SESSION['counterparty_id']);
        unset($_SESSION['counterparty_user_id']);
        unset($_SESSION['counterparty_district_scope']);
        clearSessionDemoDatabase();
        $counterpartyId = null;
        $districtScope = null;
    }

    jsonResponse([
        'user' => $login,
        'readonly' => $_SESSION['readonly'],
        'counterpartyId' => $counterpartyId,
        'districtScope' => $districtScope,
        'isCounterpartyUser' => $counterpartyId !== null,
        'usesDemoDatabase' => sessionUsesDemoDatabase(),
    ]);
}

// POST /api/logout — выход
if ($route === 'logout' && $method === 'POST') {
    $_SESSION = [];
    session_destroy();
    jsonResponse(['success' => true]);
}

// /api/counterparties
if ($route === 'counterparties' && $method === 'GET') {
    requireReadAuth($config);
    try {
        $pdo = getMysqlConnection();
        $items = listCounterparties($pdo);
        $counterpartyId = getSessionCounterpartyId();
        if ($counterpartyId !== null) {
            $items = array_values(array_filter($items, function ($item) use ($counterpartyId) {
                return (int) ($item['id'] ?? 0) === (int) $counterpartyId;
            }));
        }
        jsonResponse($items);
    } catch (Throwable $e) {
        logThrowable('counterparties_get_failed', $e);
        jsonResponse(['error' => 'Не удалось загрузить контрагентов'], 500);
    }
}

// /api/bunkers
if ($route === 'bunkers') {
    try {
        $pdo = getBunkersDb($legacyDataFile);
    } catch (Throwable $e) {
        logThrowable('mysql_connection_failed', $e, [
            'connectionMode' => getCurrentMysqlConnectionMode(),
            'mysql' => [
                'host' => sessionUsesDemoDatabase() ? (getenv('DEMO_DB_HOST') ?: (getenv('MYSQL_HOST') ?: 'localhost')) : (getenv('MYSQL_HOST') ?: 'localhost'),
                'port' => sessionUsesDemoDatabase() ? (getenv('DEMO_DB_PORT') ?: (getenv('MYSQL_PORT') ?: '3306')) : (getenv('MYSQL_PORT') ?: '3306'),
                'user' => sessionUsesDemoDatabase() ? (getenv('DEMO_DB_USERNAME') ?: (getenv('DEMO_DB_USER') ?: (getenv('MYSQL_USER') ?: 'map_service'))) : (getenv('MYSQL_USER') ?: 'map_service'),
                'database' => sessionUsesDemoDatabase() ? (getenv('DEMO_DB_DATABASE') ?: '') : (getenv('MYSQL_DATABASE') ?: 'map_service'),
                'passwordLength' => strlen((string) (sessionUsesDemoDatabase() ? (getenv('DEMO_DB_PASSWORD') ?: (getenv('MYSQL_PASSWORD') ?: '')) : (getenv('MYSQL_PASSWORD') ?: ''))),
            ],
            'pdoDrivers' => class_exists('PDO') ? PDO::getAvailableDrivers() : [],
        ]);
        if (strpos($e->getMessage(), 'PDO MySQL driver is not installed') !== false || strpos($e->getMessage(), 'could not find driver') !== false) {
            jsonResponse(['error' => 'Не удалось подключиться к MySQL: в PHP не включен драйвер pdo_mysql'], 500);
        }
        if (strpos($e->getMessage(), 'Demo database connection is not configured') !== false) {
            jsonResponse(['error' => 'Демо-БД не настроена'], 503);
        }
        jsonResponse(['error' => 'Не удалось подключиться к MySQL'], 500);
    }

    $sessionCounterpartyId = getSessionCounterpartyId();
    $sessionDistrictScopeTokens = getSessionDistrictScopeTokens();

    // GET /api/bunkers — список с фильтрацией
    if ($method === 'GET' && !$id) {
        requireReadAuth($config);
        try {
            $filters = [
                'district' => $_GET['district'] ?? '',
                'wasteType' => $_GET['wasteType'] ?? '',
                'contractor' => $_GET['contractor'] ?? '',
                'counterpartyId' => $_GET['counterpartyId'] ?? '',
            ];

            if ($sessionCounterpartyId !== null) {
                $filters['counterpartyId'] = $sessionCounterpartyId;
            }
            if (!empty($sessionDistrictScopeTokens)) {
                $filters['districtScopeTokens'] = $sessionDistrictScopeTokens;
            }

            $bunkers = listBunkers($pdo, $filters);
            jsonResponse($bunkers);
        } catch (Throwable $e) {
            logThrowable('bunkers_get_failed', $e);
            jsonResponse(['error' => 'Не удалось загрузить бункеры'], 500);
        }
    }

    // POST /api/bunkers/:id/mark-filled — отметить заполненным
    if ($method === 'POST' && $id && $idAction === 'mark-filled') {
        requireWriteAuth($config, true);
        try {
            $bunker = getBunkerById($pdo, $id);
            if (!$bunker) {
                jsonResponse(['error' => 'Бункер не найден'], 404);
            }

            ensureCounterpartyCanAccessBunker($bunker, $sessionCounterpartyId, $sessionDistrictScopeTokens);

            $isBotRequest = isBotAuthed($config);
            $filledBy = $isBotRequest
                ? 'bot'
                : (string) ($_SESSION['user'] ?? 'unknown');

            $updated = markBunkerFilled($pdo, $id, $filledBy, 100);
            if (!$updated) {
                jsonResponse(['error' => 'Бункер не найден'], 404);
            }

            if (!$isBotRequest && !sessionUsesDemoDatabase()) {
                $maxMessage = buildMaxMarkFilledMessage($updated, $filledBy);
                $maxResult = sendMaxChatMessage($config, $maxMessage);
                if (!empty($maxResult['enabled'])) {
                    $updated['maxNotificationSent'] = !empty($maxResult['sent']);
                }
            }

            jsonResponse($updated);
        } catch (Throwable $e) {
            logThrowable('bunkers_mark_filled_failed', $e, ['bunkerId' => $id]);
            if ($e instanceof InvalidArgumentException) {
                jsonResponse(['error' => $e->getMessage()], 400);
            }
            jsonResponse(['error' => 'Не удалось отметить бункер заполненным'], 500);
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
            if ($e instanceof InvalidArgumentException) {
                jsonResponse(['error' => $e->getMessage()], 400);
            }
            jsonResponse(['error' => 'Не удалось создать бункер'], 500);
        }
    }

    // PUT /api/bunkers/:id — обновление
    if ($method === 'PUT' && $id && !$idAction) {
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
            if ($e instanceof InvalidArgumentException) {
                jsonResponse(['error' => $e->getMessage()], 400);
            }
            jsonResponse(['error' => 'Не удалось обновить бункер'], 500);
        }
    }

    // DELETE /api/bunkers/:id — удаление
    if ($method === 'DELETE' && $id && !$idAction) {
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
