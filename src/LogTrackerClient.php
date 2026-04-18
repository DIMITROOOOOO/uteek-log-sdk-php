<?php declare(strict_types=1);

namespace Uteek\LogTracker;

use Exception;
use Throwable;

class LogTrackerClient
{
    public const VERSION = '1.3.0';

    /** Minimum length enforced for API keys issued by the monitoring dashboard. */
    private const MIN_KEY_LENGTH = 16;

    private const LEVEL_WEIGHTS = [
        'DEBUG'    => 0,
        'INFO'     => 1,
        'WARNING'  => 2,
        'ERROR'    => 3,
        'CRITICAL' => 4,
    ];

    private string $apiUrl;
    private string $apiKey;
    private string $projectId;
    private string $projectName;
    private string $environment;
    private string $framework;
    private array $enabledLevels;
    private array $buffer = [];
    private int $maxBatchSize;
    private array $userContext = [];

    /**
     * Set to true when the Node.js backend rejects the API key (HTTP 401/403).
     * No further network calls are made once disabled.
     */
    private bool $disabled = false;

    /**
     * In debug mode, auth failures throw an exception instead of silently disabling.
     * Set via config['debug'] = true. Never enable in production.
     */
    private bool $debugMode = false;

    public function __construct(array $config)
    {
        $rawKey = $config['api_key'] ?? null;
        if (!is_string($rawKey) || trim($rawKey) === '') {
            throw new Exception('LogTracker: api_key is required.');
        }
        if (strlen(trim($rawKey)) < self::MIN_KEY_LENGTH) {
            throw new Exception(
                'LogTracker: api_key appears invalid (too short). ' .
                'Copy the key from your UTEEK monitoring dashboard.'
            );
        }

        $rawProject     = $config['project_id']   ?? null;
        $rawProjectName = $config['project_name'] ?? null;

        if ((!is_string($rawProject) || trim($rawProject) === '') &&
            (!is_string($rawProjectName) || trim($rawProjectName) === '')) {
            throw new Exception('LogTracker: project_id or project_name is required.');
        }

        $this->apiUrl       = rtrim($config['api_url'] ?? 'http://localhost:3000', '/') . '/ingest';
        $this->apiKey       = trim($rawKey);
        $this->projectId    = is_string($rawProject) ? trim($rawProject) : '';
        $this->projectName  = is_string($rawProjectName) ? trim($rawProjectName) : '';
        $this->environment  = $config['environment'] ?? 'production';
        $this->maxBatchSize = max(1, (int)($config['batch_size'] ?? 50)); // Bug 7: clamp to minimum 1
        $this->framework    = $config['framework'] ?? $this->detectFramework();
        $this->debugMode    = (bool)($config['debug'] ?? false);

        $levels = $config['log_levels'] ?? array_keys(self::LEVEL_WEIGHTS);
        $this->enabledLevels = array_map('strtoupper', $levels);

        // Bug 6: only register shutdown flush for web requests, not long-lived CLI processes
        if (PHP_SAPI !== 'cli') {
            register_shutdown_function([$this, 'flush']);
        }
    }

    // ─── User Context ─────────────────────────────────────────────────────────

    public function setUser(array $user): void
    {
        $this->userContext = $user;
    }

    // ─── Handler Registration ─────────────────────────────────────────────────

    /**
     * Register a PHP error handler that captures errors and forwards them to the API.
     * Returns false so PHP's default handler also runs.
     */
    public function registerErrorHandler(): void
    {
        set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
            $level = match (true) {
                in_array($errno, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)       => 'ERROR',
                in_array($errno, [E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING], true) => 'WARNING',
                default => 'INFO',
            };

            $this->push([
                'level'   => $level,
                'message' => $errstr,
                'context' => [
                    'error_code' => $errno,
                    'file'       => $errfile,
                    'line'       => $errline,
                ],
            ]);

            return false; // let PHP's default handler run as well
        });
    }

    /**
     * Register an uncaught exception handler. Flushes immediately on exception.
     */
    public function registerExceptionHandler(): void
    {
        set_exception_handler(function (Throwable $e): void {
            $this->captureException($e);
            $this->flush();
        });
    }

    // ─── Logging Methods ──────────────────────────────────────────────────────

    // Bug 4: accept $level so callers can promote exceptions to CRITICAL
    public function captureException(Throwable $e, array $context = [], string $level = 'ERROR'): void
    {
        $this->push([
            'level'       => strtoupper($level),
            'message'     => $e->getMessage(),
            'stack_trace' => $e->getTraceAsString(),
            'context'     => array_merge([
                'exception_class' => get_class($e),
                'file'            => $e->getFile(),
                'line'            => $e->getLine(),
                'code'            => $e->getCode(),
            ], $context),
        ]);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->push(['level' => 'DEBUG', 'message' => $message, 'context' => $context]);
    }

    public function info(string $message, array $context = []): void
    {
        $this->push(['level' => 'INFO', 'message' => $message, 'context' => $context]);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->push(['level' => 'WARNING', 'message' => $message, 'context' => $context]);
    }

    public function error(string $message, array $context = []): void
    {
        $this->push(['level' => 'ERROR', 'message' => $message, 'context' => $context]);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->push(['level' => 'CRITICAL', 'message' => $message, 'context' => $context]);
    }

    // ─── State ────────────────────────────────────────────────────────────────

    /**
     * Returns true if the client was disabled due to an authentication failure.
     * Useful for checking during setup / tests.
     */
    public function isDisabled(): bool
    {
        return $this->disabled;
    }

    /**
     * Verify that the API key is accepted by the Node.js backend without buffering
     * any real logs. Returns true on success, false on auth failure.
     *
     * Call this in your project's health-check or deployment hooks:
     *   if (!$client->ping()) { throw new Exception('Invalid UTEEK API key'); }
     */
    public function ping(): bool
    {
        // Bug 2: build /ping URL by trimming /ingest suffix instead of str_replace
        // to avoid corrupting URLs that contain "ingest" elsewhere (e.g. http://ingest.host/api/ingest)
        $pingUrl = substr($this->apiUrl, 0, -strlen('/ingest')) . '/ping';
        $ch = curl_init($pingUrl);
        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_HTTPGET        => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER     => [
                'X-API-Key: ' . $this->apiKey,
                'X-SDK-Version: ' . self::VERSION,
            ],
        ]);

        // Bug 2: check curl_errno so connection failures return false instead of silently passing
        $result   = curl_exec($ch);
        $curlErr  = curl_errno($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result === false || $curlErr !== 0) {
            return false;
        }

        if ($httpCode === 401 || $httpCode === 403) {
            $this->handleAuthFailure($httpCode);
            return false;
        }

        return $httpCode >= 200 && $httpCode < 300;
    }

    // ─── Internals ────────────────────────────────────────────────────────────

    private function push(array $log): void
    {
        if ($this->disabled) {
            return;
        }

        if (!in_array($log['level'], $this->enabledLevels, true)) {
            return;
        }

        $now                = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $log['ts']          = (int)(microtime(true) * 1000);
        $log['date']        = $now->format('Y-m-d');
        $log['datetime']    = $now->format(\DateTimeInterface::ATOM);
        $log['project_id']   = $this->projectId;
        $log['project_name'] = $this->projectName;
        $log['environment']  = $this->environment;
        $log['app']         = 'php';
        $log['framework']   = $this->framework;
        $log['host']        = gethostname() ?: 'unknown';
        $log['sdk_version'] = self::VERSION;

        // Bug 5: only auto-generate a stack trace for DEBUG; other levels get it from captureException
        if (!isset($log['stack_trace']) && $log['level'] === 'DEBUG') {
            $log['stack_trace'] = (new \Exception())->getTraceAsString();
        }

        $log['context'] = array_merge(
            $log['context'] ?? [],
            $this->buildRequestContext(),
            $this->buildServerContext(),
            !empty($this->userContext) ? ['user' => $this->userContext] : []
        );

        $this->buffer[] = $log;

        if (count($this->buffer) >= $this->maxBatchSize) {
            $this->flush();
        }
    }

    private function buildRequestContext(): array
    {
        if (PHP_SAPI === 'cli' || empty($_SERVER['REQUEST_METHOD'])) {
            return [];
        }

        return [
            'request' => [
                'method'     => $_SERVER['REQUEST_METHOD'],
                'url'        => $this->buildRequestUrl(),
                'ip'         => $this->getClientIp(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ],
        ];
    }

    private function buildRequestUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $uri    = $_SERVER['REQUEST_URI'] ?? '/';

        return $scheme . '://' . $host . $uri;
    }

    private function getClientIp(): ?string
    {
        // HTTP_X_FORWARDED_FOR may contain a comma-separated list; take the first entry.
        // Note: this header can be spoofed; it is used here for logging purposes only.
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        if ($forwarded !== null) {
            return trim(explode(',', $forwarded)[0]);
        }

        return $_SERVER['REMOTE_ADDR'] ?? null;
    }

    private function buildServerContext(): array
    {
        return [
            'server' => [
                'php_version' => PHP_VERSION,
                'os'          => PHP_OS_FAMILY,
                'sapi'        => PHP_SAPI,
            ],
        ];
    }

    private function detectFramework(): string
    {
        if (defined('LARAVEL_START') || class_exists('\Illuminate\Foundation\Application', false)) {
            return 'laravel';
        }
        if (defined('ABSPATH') || function_exists('add_action')) {
            return 'wordpress';
        }
        if (class_exists('\Symfony\Component\HttpKernel\Kernel', false)) {
            return 'symfony';
        }
        return 'php';
    }

    // ─── Flush ────────────────────────────────────────────────────────────────

    /**
     * Send all buffered logs to the Node.js backend and clear the buffer.
     * Called automatically at shutdown via register_shutdown_function (web only).
     */
    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $payload = json_encode($this->buffer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            $this->buffer = [];
            return;
        }

        // If running under PHP-FPM, finish the response first so the HTTP client
        // does not wait for the log send to complete.
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // Bug 3: only clear buffer after a confirmed successful send
        if ($this->send($payload)) {
            $this->buffer = [];
        }
    }

    // Bug 1 & 3: returns true on success so flush() knows whether to clear the buffer
    private function send(string $payload): bool
    {
        $ch = curl_init($this->apiUrl);
        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-API-Key: ' . $this->apiKey,
                'X-SDK-Version: ' . self::VERSION,
            ],
        ]);

        // Bug 1: check curl_errno so connection failures (refused, timeout, DNS) are detected
        $result   = curl_exec($ch);
        $curlErr  = curl_errno($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result === false || $curlErr !== 0) {
            if ($this->debugMode) {
                error_log('LogTracker: could not reach ingest server (cURL #' . $curlErr . ')');
            }
            return false;
        }

        if ($httpCode === 401 || $httpCode === 403) {
            $this->handleAuthFailure($httpCode);
            return false;
        }

        return $httpCode >= 200 && $httpCode < 300;
    }

    /**
     * Called when the Node.js backend rejects authentication.
     * Disables the client so no further HTTP calls are made.
     * The actual API key is never included in error messages.
     */
    private function handleAuthFailure(int $httpCode): void
    {
        $this->disabled = true;
        $this->buffer   = [];

        $message = sprintf(
            'LogTracker: API key rejected by the ingest server (HTTP %d). ' .
            'Verify the key in your UTEEK monitoring dashboard. Log tracking is now disabled.',
            $httpCode
        );

        if ($this->debugMode) {
            throw new Exception($message);
        }

        error_log($message);
    }
}
