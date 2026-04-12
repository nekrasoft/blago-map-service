<?php

function getSsoSecret()
{
    return trim((string) (getenv('CROSS_SERVICE_SSO_SECRET') ?: ''));
}

function getSsoTtlSeconds()
{
    $ttl = (int) (getenv('CROSS_SERVICE_SSO_TTL_SECONDS') ?: 90);

    return max(30, $ttl);
}

function base64UrlEncodeSso($value)
{
    return rtrim(strtr(base64_encode((string) $value), '+/', '-_'), '=');
}

function base64UrlDecodeSso($value)
{
    $value = (string) $value;
    $padding = strlen($value) % 4;

    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    if ($decoded === false) {
        throw new InvalidArgumentException('Invalid base64url token value.');
    }

    return $decoded;
}

function issueSsoToken(array $claims, $secret, $ttlSeconds)
{
    $secret = trim((string) $secret);
    if ($secret === '') {
        throw new RuntimeException('CROSS_SERVICE_SSO_SECRET is not configured.');
    }

    $now = time();
    $payload = array_merge($claims, [
        'iat' => $now,
        'exp' => $now + max(30, (int) $ttlSeconds),
        'nonce' => bin2hex(random_bytes(16)),
    ]);

    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payloadJson === false) {
        throw new RuntimeException('Failed to encode SSO payload.');
    }

    $encodedPayload = base64UrlEncodeSso($payloadJson);
    $signature = hash_hmac('sha256', $encodedPayload, $secret, true);

    return $encodedPayload . '.' . base64UrlEncodeSso($signature);
}

function validateSsoToken($token, $secret)
{
    $secret = trim((string) $secret);
    if ($secret === '') {
        throw new RuntimeException('CROSS_SERVICE_SSO_SECRET is not configured.');
    }

    $token = trim((string) $token);
    if ($token === '') {
        throw new InvalidArgumentException('SSO token is missing.');
    }

    $parts = explode('.', $token, 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        throw new InvalidArgumentException('Malformed SSO token.');
    }

    $encodedPayload = $parts[0];
    $encodedSignature = $parts[1];

    $expectedSignature = base64UrlEncodeSso(hash_hmac('sha256', $encodedPayload, $secret, true));
    if (!hash_equals($expectedSignature, $encodedSignature)) {
        throw new InvalidArgumentException('Invalid SSO token signature.');
    }

    $payloadJson = base64UrlDecodeSso($encodedPayload);
    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Invalid SSO token payload.');
    }

    $exp = isset($payload['exp']) ? (int) $payload['exp'] : 0;
    if ($exp <= 0 || $exp < time()) {
        throw new InvalidArgumentException('Expired SSO token.');
    }

    return $payload;
}

function getSsoMysqlConnection()
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
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

function ensureCounterpartyUsersTableSso($pdo)
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
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_counterparty_users_login (login),
    KEY idx_counterparty_users_counterparty_id (counterparty_id),
    KEY idx_counterparty_users_district_scope (district_scope),
    KEY idx_counterparty_users_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    $pdo->exec($sql);
    $ensured = true;
}

function findActiveCounterpartyUserByIdSso($id)
{
    $id = (int) $id;
    if ($id <= 0) {
        return null;
    }

    $pdo = getSsoMysqlConnection();
    ensureCounterpartyUsersTableSso($pdo);

    $stmt = $pdo->prepare(
        'SELECT id, login, counterparty_id, district_scope, is_active
         FROM counterparty_users
         WHERE id = :id AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute(['id' => $id]);

    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'login' => (string) ($row['login'] ?? ''),
        'counterparty_id' => (int) ($row['counterparty_id'] ?? 0),
        'district_scope' => isset($row['district_scope']) ? trim((string) $row['district_scope']) : null,
    ];
}

function findActiveCounterpartyUserByLoginSso($login)
{
    $login = trim((string) $login);
    if ($login === '') {
        return null;
    }

    $pdo = getSsoMysqlConnection();
    ensureCounterpartyUsersTableSso($pdo);

    $stmt = $pdo->prepare(
        'SELECT id, login, counterparty_id, district_scope, is_active
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
        'counterparty_id' => (int) ($row['counterparty_id'] ?? 0),
        'district_scope' => isset($row['district_scope']) ? trim((string) $row['district_scope']) : null,
    ];
}
