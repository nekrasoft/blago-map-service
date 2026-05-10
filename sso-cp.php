<?php
require __DIR__ . '/auth.inc.php';
require __DIR__ . '/sso.inc.php';

requireMapAuth($config);

$counterpartyId = isset($_SESSION['counterparty_id']) ? (int) $_SESSION['counterparty_id'] : 0;
if ($counterpartyId <= 0) {
    header('Location: /');
    exit;
}

$counterpartyUserId = isset($_SESSION['counterparty_user_id']) ? (int) $_SESSION['counterparty_user_id'] : 0;
if ($counterpartyUserId <= 0) {
    $login = trim((string) ($_SESSION['user'] ?? ''));
    $counterpartyUser = findActiveCounterpartyUserByLoginSso($login);
    $counterpartyUserId = (int) ($counterpartyUser['id'] ?? 0);
    if ($counterpartyUserId > 0) {
        $_SESSION['counterparty_user_id'] = $counterpartyUserId;
    }
}

if ($counterpartyUserId <= 0) {
    header('Location: /');
    exit;
}

$counterpartyUser = findActiveCounterpartyUserByIdSso($counterpartyUserId);
if (!$counterpartyUser || (int) ($counterpartyUser['counterparty_id'] ?? 0) !== $counterpartyId) {
    header('Location: /');
    exit;
}
$_SESSION[MAP_DEMO_DB_SESSION_KEY] = !empty($counterpartyUser['is_demo']);

try {
    $token = issueSsoToken(
        [
            'direction' => 'map_to_cp',
            'uid' => $counterpartyUserId,
            'counterparty_id' => $counterpartyId,
        ],
        getSsoSecret(),
        getSsoTtlSeconds()
    );
} catch (Throwable $e) {
    error_log('map-service sso-cp error: ' . $e->getMessage());
    http_response_code(500);
    echo 'SSO временно недоступен.';
    exit;
}

$cpServiceUrl = rtrim((string) (getenv('CP_SERVICE_URL') ?: 'https://cp.blagokirov.ru'), '/');
header('Location: ' . $cpServiceUrl . '/billing/sso-login?token=' . rawurlencode($token));
exit;
