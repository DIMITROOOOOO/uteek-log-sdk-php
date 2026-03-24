<?php declare(strict_types=1);

namespace Uteek\LogTracker\Laravel;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;
use Uteek\LogTracker\LogTrackerClient;

/**
 * Monolog handler that forwards log records to the UTEEK Log Tracker API.
 * Compatible with Monolog ^3.0 (Laravel 10+).
 *
 * Register in config/logging.php:
 *
 *   'logtracker' => [
 *       'driver'  => 'monolog',
 *       'handler' => \Uteek\LogTracker\Laravel\LogTrackerHandler::class,
 *       'with'    => ['client' => app(\Uteek\LogTracker\LogTrackerClient::class)],
 *   ],
 */
class LogTrackerHandler extends AbstractProcessingHandler
{
    private LogTrackerClient $client;

    public function __construct(
        LogTrackerClient $client,
        int|string|Level $level = Level::Debug,
        bool $bubble = true
    ) {
        parent::__construct($level, $bubble);
        $this->client = $client;
    }

    protected function write(LogRecord $record): void
    {
        // If the record carries an exception, use captureException for full stack trace.
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
