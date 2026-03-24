<?php

require __DIR__ . '/../vendor/autoload.php';

use Uteek\LogTracker\Client;

$client = new Client([
    'ingest_url' => 'http://localhost:3000/ingest',
    'api_key' => 'ltk_dev_test123',
    'project_id' => 'laravel_logs',
]);

// Path to Laravel log file
$logFile = 'C:\Users\user\Desktop\pfe\backend-laravel-tracking-logs-mohamed\storage\logs\laravel.log';

if (!file_exists($logFile)) {
    echo "Log file not found: $logFile\n";
    echo "Please provide the correct path.\n";
    exit(1);
}

echo "Reading log file: $logFile\n\n";

$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$currentLog = null;
$sentCount = 0;

foreach ($lines as $line) {
    // Laravel log format: [2024-01-20 10:30:45] local.ERROR: message
    if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \w+\.(\w+): (.+)$/', $line, $matches)) {
        
        // Send previous log if exists
        if ($currentLog) {
            $client->capture(
                $currentLog['level'],
                $currentLog['message'],
                ['stack_trace' => $currentLog['stack']]
            );
            $sentCount++;
        }
        
        // Start new log entry
        $currentLog = [
            'timestamp' => $matches[1],
            'level' => strtoupper($matches[2]),
            'message' => $matches[3],
            'stack' => ''
        ];
        
    } elseif ($currentLog && (strpos($line, 'Stack trace:') !== false || strpos($line, '#') === 0)) {
        // Append stack trace lines
        $currentLog['stack'] .= $line . "\n";
    }
}

// Send last log
if ($currentLog) {
    $client->capture(
        $currentLog['level'],
        $currentLog['message'],
        ['stack_trace' => $currentLog['stack']]
    );
    $sentCount++;
}

$client->flush();

echo "========================================\n";
echo "  SUCCESS!\n";
echo "========================================\n";
echo "  Sent $sentCount log entries\n";
echo "  Project: laravel_logs\n";
echo "========================================\n\n";

$today = date('Y-m-d');
echo "View logs:\n";
echo "http://localhost:3000/logs/laravel_logs?date=$today\n\n";
