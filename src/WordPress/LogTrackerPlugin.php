<?php declare(strict_types=1);

namespace Uteek\LogTracker\WordPress;

use Uteek\LogTracker\LogTrackerClient;

/**
 * WordPress integration helper.
 *
 * Usage in your plugin file:
 *
 *   use Uteek\LogTracker\WordPress\LogTrackerPlugin;
 *
 *   $plugin = new LogTrackerPlugin([
 *       'api_url'    => LOGTRACKER_API_URL,
 *       'project_id' => LOGTRACKER_PROJECT_ID,
 *       'api_key'    => LOGTRACKER_API_KEY,
 *   ]);
 *
 *   add_action('init', [$plugin, 'register']);
 */
class LogTrackerPlugin
{
    private LogTrackerClient $client;

    public function __construct(array $config)
    {
        $this->client = new LogTrackerClient($config);
    }

    /**
     * Register PHP error/exception handlers and WordPress-specific hooks.
     * Call this inside the 'init' action.
     */
    public function register(): void
    {
        $this->client->registerErrorHandler();
        $this->client->registerExceptionHandler();

        // Capture fatal errors surfaced through wp_die.
        add_filter('wp_die_handler', [$this, 'wrapWpDieHandler']);

        // Set logged-in user context if available.
        add_action('wp_loaded', [$this, 'setWordPressUserContext']);
    }

    /**
     * Enrich the SDK user context with the current WordPress user.
     */
    public function setWordPressUserContext(): void
    {
        if (!function_exists('wp_get_current_user')) {
            return;
        }

        $user = wp_get_current_user();
        if ($user->ID === 0) {
            return; // not logged in
        }

        $this->client->setUser([
            'id'    => $user->ID,
            'login' => $user->user_login,
            'email' => $user->user_email,
            'roles' => $user->roles,
        ]);
    }

    /**
     * Wrap the wp_die handler to capture the error message before WordPress
     * terminates the request.
     */
    public function wrapWpDieHandler(callable $handler): callable
    {
        return function ($message, $title = '', $args = []) use ($handler): void {
            if (is_string($message) && $message !== '') {
                $this->client->error('wp_die: ' . $message, [
                    'title' => $title,
                ]);
                $this->client->flush();
            }
            $handler($message, $title, $args);
        };
    }

    public function getClient(): LogTrackerClient
    {
        return $this->client;
    }
}
