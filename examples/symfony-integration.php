<?php

/**
 * Symfony Integration Example
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * 1. composer require uteek/log-tracker-sdk
 *
 * 2. Add to .env:
 *       LOGTRACKER_API_URL=https://logs-api.uteek.net    # Node.js ingest server
 *       LOGTRACKER_PROJECT_ID=proj_abc123
 *       LOGTRACKER_API_KEY=your-secret-key
 *
 * 3. config/services.yaml:
 *
 *   Uteek\LogTracker\LogTrackerClient:
 *     arguments:
 *       $config:
 *         api_url:     '%env(LOGTRACKER_API_URL)%'
 *         api_key:     '%env(LOGTRACKER_API_KEY)%'
 *         project_id:  '%env(LOGTRACKER_PROJECT_ID)%'
 *         environment: '%kernel.environment%'
 *         log_levels:  [warning, error, critical]
 *         framework:   symfony
 *
 *   Uteek\LogTracker\Symfony\LogTrackerHandler:
 *     arguments:
 *       $client: '@Uteek\LogTracker\LogTrackerClient'
 *
 * 4. config/packages/monolog.yaml:
 *
 *   monolog:
 *     handlers:
 *       logtracker:
 *         type:    service
 *         id:      Uteek\LogTracker\Symfony\LogTrackerHandler
 *         level:   warning
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Manual usage inside a controller or service:
 */

use Uteek\LogTracker\LogTrackerClient;

// Using dependency injection in a Symfony controller/service:
class OrderController
{
    public function __construct(private readonly LogTrackerClient $tracker) {}

    public function checkout(): void
    {
        // Attach the current user context
        $this->tracker->setUser([
            'id'    => 42,
            'email' => 'user@example.com',
            'roles' => ['ROLE_USER'],
        ]);

        try {
            // ... payment processing
        } catch (\Exception $e) {
            $this->tracker->captureException($e, [
                'order_id'      => 99,
                'extra_context' => 'Checkout payment',
            ]);
        }

        $this->tracker->info('Order placed', ['order_id' => 99]);
    }
}
