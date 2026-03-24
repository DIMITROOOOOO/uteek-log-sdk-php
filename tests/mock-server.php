<?php

/**
 * UTEEK Log Tracker – Mock Node.js Ingest Server
 * ─────────────────────────────────────────────────────────────────────────────
 * Simulates the Node.js backend so you can test the SDK locally before the
 * real backend is ready.
 *
 * Start it with PHP's built-in server:
 *   php -S localhost:3000 tests/mock-server.php
 *
 * Then run the SDK test in another terminal:
 *   php tests/run-test.php
 * ─────────────────────────────────────────────────────────────────────────────
 */

// Validate the API key the same way the real Node.js backend will.
// Adjust VALID_API_KEY to match whatever key you use in your test.
define('VALID_API_KEY', 'ltk_dev_test_key_abc123xyz');

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

header('Content-Type: application/json');

// ─── GET /ping ────────────────────────────────────────────────────────────────
if ($method === 'GET' && $path === '/ping') {
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';

    if ($key !== VALID_API_KEY) {
        http_response_code(401);
        echoLog('PING', 'REJECTED', $key ? '[key present but wrong]' : '[no key]');
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }

    http_response_code(200);
    echoLog('PING', 'OK', 'API key accepted');
    echo json_encode(['status' => 'ok']);
    return;
}

// ─── POST /ingest ─────────────────────────────────────────────────────────────
if ($method === 'POST' && $path === '/ingest') {
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';

    if ($key !== VALID_API_KEY) {
        http_response_code(401);
        echoLog('INGEST', 'REJECTED', $key ? '[key present but wrong]' : '[no key]');
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }

    $raw  = file_get_contents('php://input');
    $logs = json_decode($raw, true);

    if (!is_array($logs)) {
        http_response_code(400);
        echoLog('INGEST', 'BAD REQUEST', 'Invalid JSON body');
        echo json_encode(['error' => 'Invalid payload']);
        return;
    }

    $count = count($logs);
    echoLog('INGEST', 'RECEIVED', "$count log(s)");

    foreach ($logs as $i => $log) {
        $n       = $i + 1;
        $level   = str_pad($log['level'] ?? '?', 8);
        $msg     = $log['message'] ?? '(no message)';
        $dt      = $log['datetime'] ?? $log['date'] ?? '?';
        $fw      = $log['framework'] ?? '?';
        $env     = $log['environment'] ?? '?';
        echo "  [$n] $level | $dt | $fw | $env | $msg\n";
    }

    // Simulate what MongoDB would store (pretty-print to the terminal).
    $storageFile = __DIR__ . '/received-logs.json';
    $existing    = file_exists($storageFile)
        ? (json_decode(file_get_contents($storageFile), true) ?? [])
        : [];
    $existing    = array_merge($existing, $logs);
    file_put_contents($storageFile, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    http_response_code(202);
    echo json_encode(['accepted' => $count]);
    return;
}

// ─── Anything else ────────────────────────────────────────────────────────────
http_response_code(404);
echo json_encode(['error' => 'Not found']);

// ─── Helper ───────────────────────────────────────────────────────────────────
function echoLog(string $endpoint, string $status, string $detail): void
{
    $ts = date('H:i:s');
    echo "[$ts] $endpoint  →  $status  |  $detail\n";
}
