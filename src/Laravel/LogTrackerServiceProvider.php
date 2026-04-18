<?php declare(strict_types=1);

namespace Uteek\LogTracker\Laravel;

use Illuminate\Support\ServiceProvider;
use Uteek\LogTracker\LogTrackerClient;

class LogTrackerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/logtracker.php',
            'logtracker'
        );

        $this->app->singleton(LogTrackerClient::class, function ($app): LogTrackerClient {
            /** @var array $cfg */
            $cfg = $app['config']['logtracker'];

            return new LogTrackerClient([
                'api_url'      => $cfg['api_url'],
                'api_key'      => $cfg['api_key'],
                'project_id'   => $cfg['project_id']   ?? null,
                'project_name' => $cfg['project_name'] ?? null,
                'environment'  => $cfg['environment'] ?? $app->environment(),
                'log_levels'   => $cfg['log_levels'] ?? ['error', 'critical', 'warning'],
                'batch_size'   => $cfg['batch_size'] ?? 50,
                'debug'        => $cfg['debug'] ?? $app->hasDebugModeEnabled(),
            ]);
        });

        $this->app->alias(LogTrackerClient::class, 'logtracker');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/logtracker.php' => config_path('logtracker.php'),
            ], 'logtracker-config');
        }
    }
}
