<?php

function claimPickupDelivery($pdo)
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->query("SELECT id, submission_key, sheet_row FROM bunker_pickup_reports
            WHERE sheets_status IN ('pending', 'retry', 'sending') AND sheets_next_attempt_at <= NOW()
            ORDER BY sheets_next_attempt_at, id LIMIT 1 FOR UPDATE");
        $row = $stmt->fetch();
        if (!$row) {
            $pdo->commit();
            return ['job' => null];
        }
        $token = bin2hex(random_bytes(16));
        $update = $pdo->prepare("UPDATE bunker_pickup_reports SET sheets_status = 'sending',
            sheets_attempts = sheets_attempts + 1, sheets_next_attempt_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE), sheets_delivery_token = :token
            WHERE id = :id");
        $update->execute(['id' => $row['id'], 'token' => $token]);
        $pdo->commit();
        return ['job' => ['id' => (int) $row['id'], 'submissionKey' => $row['submission_key'],
            'row' => json_decode($row['sheet_row'], true), 'token' => $token]];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function acknowledgePickupDelivery($pdo, $body)
{
    $sent = ($body['sent'] ?? false) === true;
    $stmt = $pdo->prepare("UPDATE bunker_pickup_reports SET sheets_status = :status,
        sheets_next_attempt_at = CASE WHEN :sent THEN NULL ELSE DATE_ADD(NOW(), INTERVAL 1 MINUTE) END, sheets_error = :error, sheets_delivery_token = NULL
        WHERE id = :id AND sheets_delivery_token = :token AND sheets_status = 'sending'");
    $stmt->execute(['id' => (int) ($body['id'] ?? 0), 'token' => (string) ($body['token'] ?? ''),
        'status' => $sent ? 'sent' : 'retry', 'sent' => $sent ? 1 : 0,
        'error' => $sent ? null : 'Таблица временно недоступна. Доставка будет повторена автоматически.']);
}
