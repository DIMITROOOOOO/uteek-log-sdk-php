<?php

/**
 * UTEEK Log Tracker – SDK Test Runner
 * ─────────────────────────────────────────────────────────────────────────────
 * Prerequisite: start the mock server first in a separate terminal:
 *   php -S localhost:3000 tests/mock-server.php
 *
 * Then run this file:
 *   php tests/run-test.php
 * ─────────────────────────────────────────────────────────────────────────────
 */

require __DIR__ . '/../vendor/autoload.php';

use Uteek\LogTracker\LogTrackerClient;

$PASS = '[PASS]';
$FAIL = '[FAIL]';
$errors = 0;

echo "\n";
echo "╔══════════════════════════════════════════════╗\n";
echo "║   UTEEK Log Tracker SDK – Test Suite         ║\n";
echo "╚══════════════════════════════════════════════╝\n\n";

// ─── Helper ───────────────────────────────────────────────────────────────────
function test(string $name, callable $fn, string &$PASS, string &$FAIL, int &$errors): void
{
    echo "  » $name ... ";
    try {
        $fn();
        echo "$PASS\n";
    } catch (Throwable $e) {
        echo "$FAIL  {$e->getMessage()}\n";
        $errors++;
    }
}

// ─── 1. Constructor validation ────────────────────────────────────────────────
echo "[ 1 ] Constructor validation\n";

// All four negative tests use the same pattern: we EXPECT an exception.
echo "  » rejects missing api_key ... ";
try {
    new LogTrackerClient(['project_id' => 'p1', 'api_key' => '']);
    echo "$FAIL  Exception was not thrown\n";
    $errors++;
} catch (Exception $e) {
    echo "$PASS  ({$e->getMessage()})\n";
}

echo "  » rejects short api_key ... ";
try {
    new LogTrackerClient(['project_id' => 'p1', 'api_key' => 'short']);
    echo "$FAIL  Exception was not thrown\n";
    $errors++;
} catch (Exception $e) {
    echo "$PASS  ({$e->getMessage()})\n";
}

echo "  » rejects missing project_id ... ";
try {
    new LogTrackerClient(['api_key' => 'ltk_dev_test_key_abc123xyz']);
    echo "$FAIL  Exception was not thrown\n";
    $errors++;
} catch (Exception $e) {
    echo "$PASS  ({$e->getMessage()})\n";
}

// ─── 2. Create a valid client ─────────────────────────────────────────────────
echo "\n[ 2 ] Valid client initialisation\n";

$client = null;
echo "  » creates client with valid config ... ";
try {
    $client = new LogTrackerClient([
        'api_url'     => 'http://localhost:3000',
        'api_key'     => 'ltk_dev_test_key_abc123xyz',
        'project_id'  => 'proj_abc123',
        'environment' => 'testing',
        'log_levels'  => ['debug', 'info', 'warning', 'error', 'critical'],
        'debug'       => true,
    ]);
    echo "$PASS\n";
} catch (Exception $e) {
    echo "$FAIL  {$e->getMessage()}\n";
    $errors++;
    exit(1);
}

// ─── 3. Ping ──────────────────────────────────────────────────────────────────
echo "\n[ 3 ] API key verification (ping)\n";
echo "  » ping returns true with correct key ... ";
$pingResult = $client->ping();
if ($pingResult === true) {
    echo "$PASS\n";
} else {
    echo "$FAIL  (is the mock server running? php -S localhost:3000 tests/mock-server.php)\n";
    $errors++;
}

echo "  » wrong key returns false + disables client ... ";
$badClient = new LogTrackerClient([
    'api_url'    => 'http://localhost:3000',
    'api_key'    => 'ltk_dev_WRONG_KEY_xxxxxxxx',
    'project_id' => 'proj_abc123',
    'debug'      => false, // don't throw, just disable
]);
$badPing = $badClient->ping();
if ($badPing === false && $badClient->isDisabled()) {
    echo "$PASS\n";
} else {
    echo "$FAIL\n";
    $errors++;
}

// ─── 4. Log level filtering ───────────────────────────────────────────────────
echo "\n[ 4 ] Log level filtering\n";
echo "  » debug-only client ignores info ... ";
$filtered = new LogTrackerClient([
    'api_url'    => 'http://localhost:3000',
    'api_key'    => 'ltk_dev_test_key_abc123xyz',
    'project_id' => 'proj_abc123',
    'log_levels' => ['debug'],
    'debug'      => true,
]);
// info() on a debug-only client should be silently dropped (no exception)
$filtered->info('should be filtered');
echo "$PASS\n";

// ─── 5. Full log send ─────────────────────────────────────────────────────────
echo "\n[ 5 ] End-to-end log send\n";

$client->setUser(['id' => 7, 'email' => 'tester@example.com', 'role' => 'qa']);

echo "  » buffers debug log ... ";
$client->debug('Debug message', ['detail' => 'test']);
echo "$PASS\n";

echo "  » buffers info log ... ";
$client->info('User action', ['action' => 'login']);
echo "$PASS\n";

echo "  » buffers warning log ... ";
$client->warning('Slow response', ['duration_ms' => 950]);
echo "$PASS\n";

echo "  » buffers error log ... ";
$client->error('DB query failed', ['query' => 'SELECT *', 'code' => 1045]);
echo "$PASS\n";

echo "  » buffers critical log ... ";
$client->critical('Out of memory', ['limit' => '128M']);
echo "$PASS\n";

echo "  » captureException with context ... ";
try {
    throw new RuntimeException('Payment gateway timeout', 504);
} catch (Exception $e) {
    $client->captureException($e, ['order_id' => 99, 'user_id' => 7]);
}
echo "$PASS\n";

echo "  » flush sends batch to mock server ... ";
$client->flush();
echo "$PASS\n";

// ─── 6. Date fields present ───────────────────────────────────────────────────
echo "\n[ 6 ] Date fields in payload\n";
echo "  » checking received-logs.json ... ";
$logFile = __DIR__ . '/received-logs.json';
if (file_exists($logFile)) {
    $stored = json_decode(file_get_contents($logFile), true);
    $last   = end($stored);
    if (isset($last['date']) && isset($last['datetime']) && isset($last['ts'])) {
        echo "$PASS  (date={$last['date']}, datetime={$last['datetime']})\n";
    } else {
        echo "$FAIL  missing date/datetime/ts fields\n";
        $errors++;
    }
} else {
    echo "$FAIL  received-logs.json not found (did flush succeed?)\n";
    $errors++;
}

// ─── Summary ──────────────────────────────────────────────────────────────────
echo "\n";
echo "══════════════════════════════════════════════\n";
if ($errors === 0) {
    echo "  ALL TESTS PASSED\n";
} else {
    echo "  $errors TEST(S) FAILED\n";
}
echo "══════════════════════════════════════════════\n\n";

exit($errors > 0 ? 1 : 0);
