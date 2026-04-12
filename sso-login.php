<?php
require __DIR__ . '/auth.inc.php';
require __DIR__ . '/sso.inc.php';

function redirectToMapLogin()
{
    header('Location: /login?sso_error=403');
    exit;
}

try {
    $secret = getSsoSecret();
    $payload = validateSsoToken($_GET['token'] ?? '', $secret);

    if (($payload['direction'] ?? '') !== 'cp_to_map') {
        redirectToMapLogin();
    }

    $userId = (int) ($payload['uid'] ?? 0);
    $counterpartyUser = findActiveCounterpartyUserByIdSso($userId);
    if (!$counterpartyUser) {
        redirectToMapLogin();
    }

    $counterpartyId = (int) ($counterpartyUser['counterparty_id'] ?? 0);
    if ($counterpartyId <= 0) {
        redirectToMapLogin();
    }

    $payloadCounterpartyId = (int) ($payload['counterparty_id'] ?? 0);
    if ($payloadCounterpartyId > 0 && $payloadCounterpartyId !== $counterpartyId) {
        redirectToMapLogin();
    }

    $_SESSION['user'] = (string) ($counterpartyUser['login'] ?? '');
    $_SESSION['readonly'] = false;
    $_SESSION['counterparty_id'] = $counterpartyId;
    $_SESSION['counterparty_user_id'] = (int) ($counterpartyUser['id'] ?? 0);

    $districtScope = trim((string) ($counterpartyUser['district_scope'] ?? ''));
    if ($districtScope !== '') {
        $_SESSION['counterparty_district_scope'] = $districtScope;
    } else {
        unset($_SESSION['counterparty_district_scope']);
    }

    header('Location: /');
    exit;
} catch (Throwable $e) {
    error_log('map-service sso-login error: ' . $e->getMessage());
    redirectToMapLogin();
}
