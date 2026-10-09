<?php

// Exercise the production SQL against SQLite; MySQL row locks are checked separately at deployment.
class PickupTestDatabase extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->sqliteCreateFunction('NOW', fn () => gmdate('Y-m-d H:i:s'));
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(str_replace(['FOR UPDATE', 'DATE_ADD(NOW(), INTERVAL 10 MINUTE)', 'DATE_ADD(NOW(), INTERVAL 1 MINUTE)'], ['', "datetime('now', '+10 minutes')", "datetime('now', '+1 minute')"], $query), $options);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
    {
        return parent::query(str_replace('FOR UPDATE', '', $query));
    }
}
function columnExists($pdo, $table, $column) { return true; }
$api = file_get_contents(dirname(__DIR__).'/api.php');
$start = strpos($api, 'function findPickupSubmission(');
$end = strpos($api, 'function cancelFillRequest(', $start);
eval(substr($api, $start, $end - $start));
require dirname(__DIR__).'/pickup_delivery.php';
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
$pdo = new PickupTestDatabase();
$pdo->exec("CREATE TABLE counterparties(id INTEGER PRIMARY KEY, requires_container_waybill INT);
INSERT INTO counterparties VALUES(1,0);
CREATE TABLE bunkers(id TEXT PRIMARY KEY, number INT, counterparty_id INT, contractor TEXT, district TEXT, address TEXT, volume NUMERIC, fill_level INT, last_pickup_date TEXT);
INSERT INTO bunkers VALUES('b1',1,1,'Клиент','Знак','Адрес',8,100,NULL);
CREATE TABLE bunker_fill_requests(id INTEGER PRIMARY KEY, bunker_id TEXT, filled_at TEXT, executed_at TEXT, cancelled_at TEXT);
INSERT INTO bunker_fill_requests VALUES(1,'b1','2026-10-09',NULL,NULL);
CREATE TABLE bunker_pickup_reports(id INTEGER PRIMARY KEY AUTOINCREMENT, submission_key TEXT UNIQUE, submission_hash TEXT,
 sheet_row TEXT, sheets_status TEXT, sheets_next_attempt_at TEXT, sheets_attempts INT DEFAULT 0, sheets_delivery_token TEXT, sheets_error TEXT,
 counterparty_id INT, contractor TEXT, driver_source TEXT, driver_user_id TEXT, driver_name TEXT, cleanup_status TEXT, cleanup_comment TEXT,
 waybill_required INT, waybill_missing_reason TEXT, billing_units NUMERIC, completed_at TEXT);
CREATE TABLE bunker_pickup_items(report_id INT, request_id INT UNIQUE, bunker_id TEXT, bunker_number INT, billing_units NUMERIC, estimated_volume_m3 NUMERIC);
CREATE TABLE bunker_pickup_files(id INTEGER PRIMARY KEY, report_id INT, kind TEXT, file_token TEXT, file_name TEXT, content_type TEXT, file_size INT, file_sha256 TEXT, file_data BLOB);");
$payload = ['submissionKey' => '12345678-1234-1234-1234-123456789abc', 'sheetRow' => ['Дата' => '09.10.2026', 'Объект' => '1,5'],
 'items' => [['bunkerId' => 'b1', 'billingUnits' => 1.5]], 'cleanupStatus' => 'cleaned', 'driverSource' => 'max', 'driverUserId' => '1'];
$result = createPickupReport($pdo, $payload, []);
$pdo->exec("INSERT INTO bunker_fill_requests VALUES(2,'b1','2026-10-10',NULL,NULL)");
check(createPickupReport($pdo, $payload, [])['id'] === $result['id'], 'Retry must return original report');
check($pdo->query('SELECT COUNT(*) FROM bunker_pickup_reports')->fetchColumn() == 1, 'No duplicate report');
check($pdo->query('SELECT executed_at FROM bunker_fill_requests WHERE id=2')->fetchColumn() === null, 'Retry must not consume a newer request');
$changed = $payload;
$changed['items'][0]['billingUnits'] = 2;
try { createPickupReport($pdo, $changed, []); throw new RuntimeException('Expected conflict'); } catch (InvalidArgumentException $expected) {}
$job = claimPickupDelivery($pdo)['job'];
check($job['id'] === $result['id'], 'Outbox was saved with report');
check(claimPickupDelivery($pdo)['job'] === null, 'Active lease excludes a concurrent worker');
acknowledgePickupDelivery($pdo, ['id' => $job['id'], 'token' => 'old-token', 'sent' => true]);
check($pdo->query('SELECT sheets_status FROM bunker_pickup_reports')->fetchColumn() === 'sending', 'Stale receipt cannot change status');
acknowledgePickupDelivery($pdo, ['id' => $job['id'], 'token' => $job['token'], 'sent' => false]);
check(claimPickupDelivery($pdo)['job'] === null, 'Retries are delayed');
$pdo->exec("UPDATE bunker_pickup_reports SET sheets_next_attempt_at='2000-01-01'");
$next = claimPickupDelivery($pdo)['job'];
check($next['token'] !== $job['token'], 'New attempt rotates receipt');
acknowledgePickupDelivery($pdo, ['id' => $next['id'], 'token' => $next['token'], 'sent' => true]);
check(claimPickupDelivery($pdo)['job'] === null, 'Completed job is not delivered again');
$payload['submissionKey'] = '22345678-1234-1234-1234-123456789abc';
$payload['items'][] = ['bunkerId' => 'missing', 'billingUnits' => 1];
try { createPickupReport($pdo, $payload, []); throw new RuntimeException('Expected invalid request'); } catch (RuntimeException $expected) {}
check($pdo->query('SELECT COUNT(*) FROM bunker_pickup_reports')->fetchColumn() == 1, 'Failed report cannot leave an outbox job');
echo "Pickup idempotency and delivery tests: OK\n";
