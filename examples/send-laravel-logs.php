<?php

require __DIR__ . '/../vendor/autoload.php';

use Uteek\LogTracker\Client;

// ===== CONFIGURATION =====
// Replace this with YOUR actual Laravel log file path
$logFilePath = 'C:\path\to\your\laravel\storage\logs\laravel.log';

// Check if user provided path as argument
if (isset($argv[1])) {
    $logFilePath = $argv[1];
}

// Verify file exists
if (!file_exists($logFilePath)) {
    echo "\n[ERROR] Log file not found: $logFilePath\n";
    echo "\nUsage: php examples/send-laravel-logs.php <path-to-laravel.log>\n";
    echo "Example: php examples/send-laravel-logs.php C:\\laravel\\storage\\logs\\laravel.log\n\n";
    exit(1);
}

// ===== INITIALIZE CLIENT =====
$client = new Client([
    'ingest_url' => 'http://localhost:3000/ingest',
    'api_key' => 'ltk_dev_test123',
    'project_id' => 'laravel_app',
]);

echo "\n========================================\n";
echo "  UTEEK Laravel Log Importer\n";
echo "========================================\n\n";

echo "Reading: $logFilePath\n";
$fileSize = filesize($logFilePath);
echo "File size: " . round($fileSize / 1024, 2) . " KB\n";

if ($fileSize == 0) {
    echo "\n[WARNING] Log file is empty!\n";
    echo "Generate some logs first:\n";
    echo "  - Visit your Laravel app and trigger errors\n";
    echo "  - Or run: php artisan tinker\n";
    echo "  - Then: Log::error('Test error');\n\n";
    exit(0);
}

echo "\nParsing Laravel logs...\n\n";

// ===== PARSE LOG FILE =====
$lines = file($logFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$totalLines = count($lines);

$currentLog = null;
$sentCount = 0;
$errorCount = 0;
$warningCount = 0;
$infoCount = 0;

foreach ($lines as $lineNumber => $line) {
    
    // Match Laravel log format: [2024-01-20 10:30:45] local.ERROR: message
    if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \w+\.(\w+): (.+)$/', $line, $matches)) {
        
        // Send previous log entry if exists
        if ($currentLog !== null) {
            $client->capture(
                $currentLog['level'],
                $currentLog['message'],
                [
                    'timestamp' => $currentLog['timestamp'],
                    'stack_trace' => trim($currentLog['stack']),
                    'source' => 'laravel',
                    'log_file' => basename($logFilePath)
                ]
            );
            
            $sentCount++;
            
            // Count by level
            switch ($currentLog['level']) {
                case 'ERROR':
                case 'CRITICAL':
                    $errorCount++;
                    break;
                case 'WARNING':
                    $warningCount++;
                    break;
                default:
                    $infoCount++;
            }
        }
        
        // Start new log entry
        $timestamp = $matches[1];
        $level = strtoupper($matches[2]);
        $message = $matches[3];
        
        $currentLog = [
            'timestamp' => $timestamp,
            'level' => $level,
            'message' => $message,
            'stack' => ''
        ];
        
    } elseif ($currentLog !== null) {
        // Append stack trace or continuation lines
        if (strpos($line, 'Stack trace:') !== false || 
            strpos($line, '#') === 0 || 
            strpos($line, '   at ') === 0) {
            $currentLog['stack'] .= $line . "\n";
        } else {
            // Multi-line message continuation
            $currentLog['message'] .= ' ' . trim($line);
        }
    }
    
    // Progress indicator every 50 lines
    if (($lineNumber + 1) % 50 == 0) {
        echo "  Processed " . ($lineNumber + 1) . " / $totalLines lines...\n";
    }
}

// Send the last log entry
if ($currentLog !== null) {
    $client->capture(
        $currentLog['level'],
        $currentLog['message'],
        [
            'timestamp' => $currentLog['timestamp'],
            'stack_trace' => trim($currentLog['stack']),
            'source' => 'laravel',
            'log_file' => basename($logFilePath)
        ]
    );
    $sentCount++;
}

// Force flush
$client->flush();

// ===== SUMMARY =====
echo "\n========================================\n";
echo "  IMPORT COMPLETE!\n";
echo "========================================\n";
echo "  Total lines read: $totalLines\n";
echo "  Logs sent: $sentCount\n";
echo "  - Errors/Critical: $errorCount\n";
echo "  - Warnings: $warningCount\n";
echo "  - Info/Debug: $infoCount\n";
echo "========================================\n\n";

$today = date('Y-m-d');
echo "View your logs:\n";
echo "  Browser: http://localhost:3000/logs/laravel_app?date=$today\n\n";
echo "Query via PowerShell:\n";
echo "  Invoke-WebRequest -Uri \"http://localhost:3000/logs/laravel_app?date=$today\" `\n";
echo "    -Headers @{\"X-API-Key\" = \"ltk_dev_test123\"} |\n";
echo "    Select-Object -ExpandProperty Content\n\n";
