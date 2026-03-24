<?php

/**
 * WordPress Plugin Integration Example
 * ─────────────────────────────────────────────────────────────────────────────
 * Plugin Name:  UTEEK Log Tracker
 * Description:  Automatic error and exception tracking for WordPress.
 * Version:      1.0.0
 * Author:       UTEEK Digital Agency
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/vendor/autoload.php';

use Uteek\LogTracker\WordPress\LogTrackerPlugin;

// Define your credentials via wp-config.php constants or environment variables.
if (!defined('LOGTRACKER_API_URL'))    define('LOGTRACKER_API_URL',    'https://logs-api.uteek.net');
if (!defined('LOGTRACKER_PROJECT_ID')) define('LOGTRACKER_PROJECT_ID', 'proj_abc123');
if (!defined('LOGTRACKER_API_KEY'))    define('LOGTRACKER_API_KEY',    'your-secret-key');

$logTrackerPlugin = new LogTrackerPlugin([
    'api_url'     => LOGTRACKER_API_URL,
    'project_id'  => LOGTRACKER_PROJECT_ID,
    'api_key'     => LOGTRACKER_API_KEY,
    'environment' => (defined('WP_ENV') ? WP_ENV : 'production'),
    'log_levels'  => ['error', 'critical', 'warning'],
]);

// Register handlers on 'init' so WordPress is fully loaded.
add_action('init', [$logTrackerPlugin, 'register']);

// Manual logging anywhere in your theme / plugin:
add_action('woocommerce_payment_complete', function (int $orderId) use ($logTrackerPlugin): void {
    $logTrackerPlugin->getClient()->info('Payment complete', ['order_id' => $orderId]);
});
