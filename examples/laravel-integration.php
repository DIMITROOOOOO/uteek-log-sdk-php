<?php

/**
 * Laravel Integration Example
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * 1. Install the SDK:
 *       composer require uteek/log-tracker-sdk
 *
 * 2. Publish the config file:
 *       php artisan vendor:publish --tag=logtracker-config
 *
 * 3. Add to .env:
 *       LOGTRACKER_API_URL=https://logs-api.uteek.net
 *       LOGTRACKER_PROJECT_ID=proj_abc123
 *       LOGTRACKER_API_KEY=your-secret-key
 *       LOGTRACKER_ENV=production
 *
 * 4. (Optional) Add to config/logging.php to route Laravel logs automatically:
 *
 *   'channels' => [
 *       'logtracker' => [
 *           'driver'  => 'monolog',
 *           'handler' => \Uteek\LogTracker\Laravel\LogTrackerHandler::class,
 *           'with'    => [
 *               'client' => app(\Uteek\LogTracker\LogTrackerClient::class),
 *           ],
 *       ],
 *
 *       // Stack with your existing channel:
 *       'stack' => [
 *           'driver'   => 'stack',
 *           'channels' => ['single', 'logtracker'],
 *       ],
 *   ],
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Manual usage inside a controller / service:
 */

// Resolve from container
/** @var \Uteek\LogTracker\LogTrackerClient $tracker */
$tracker = app('logtracker');

// Set request user context (e.g. in a middleware)
$tracker->setUser([
    'id'    => auth()->id(),
    'email' => auth()->user()?->email,
]);

// Manual log
$tracker->info('Order created', ['order_id' => 99]);

// Capture an exception with extra context
try {
    // ...
} catch (\Exception $e) {
    $tracker->captureException($e, [
        'user_id'       => auth()->id(),
        'extra_context' => 'Payment processing',
    ]);
}
