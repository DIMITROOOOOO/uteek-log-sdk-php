<?php declare(strict_types=1);

namespace Uteek\LogTracker\Symfony;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;
use Uteek\LogTracker\LogTrackerClient;

/**
 * Monolog handler that forwards Symfony log records to the UTEEK Node.js ingest endpoint.
 *
 * Registration in config/packages/monolog.yaml:
 *
 *   monolog:
 *     handlers:
 *       logtracker:
 *         type:    service
 *         id:      Uteek\LogTracker\Symfony\LogTrackerHandler
 *         level:   warning
 *
 * Register the service in config/services.yaml:
 *
 *   Uteek\LogTracker\LogTrackerClient:
 *     arguments:
 *       $config:
 *         api_url:     '%env(LOGTRACKER_API_URL)%'
 *         api_key:     '%env(LOGTRACKER_API_KEY)%'
 *         project_id:  '%env(LOGTRACKER_PROJECT_ID)%'
 *         environment: '%kernel.environment%'
 *         framework:   symfony
 *
 *   Uteek\LogTracker\Symfony\LogTrackerHandler:
 *     arguments:
 *       $client: '@Uteek\LogTracker\LogTrackerClient'
 */
class LogTrackerHandler extends AbstractProcessingHandler
{
    private LogTrackerClient $client;

    public function __construct(
        LogTrackerClient $client,
        int|string|Level $level = Level::Warning,
        bool $bubble = true
    ) {
        parent::__construct($level, $bubble);
        $this->client = $client;
    }

    protected function write(LogRecord $record): void
    {
        // Use captureException when the context carries a real Throwable.
        $exception = $record->context['exception'] ?? null;
        if ($exception instanceof Throwable) {
            $extra = array_diff_key($record->context, ['exception' => null]);
            $this->client->captureException($exception, $extra);
            return;
        }

        $method = match (true) {
            $record->level->value >= Level::Critical->value => 'critical',
            $record->level === Level::Error                 => 'error',
            $record->level === Level::Warning               => 'warning',
            $record->level === Level::Notice                => 'info',
            $record->level === Level::Info                  => 'info',
            default                                         => 'debug',
        };

        $this->client->$method($record->message, $record->context);
    }
}
