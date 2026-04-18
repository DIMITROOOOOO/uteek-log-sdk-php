<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Enable / Disable
    |--------------------------------------------------------------------------
    */
    'enabled' => env('LOGTRACKER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | API Connection
    |--------------------------------------------------------------------------
    */
    'api_url'      => env('LOGTRACKER_API_URL', 'http://localhost:3000'),
    'project_id'   => env('LOGTRACKER_PROJECT_ID'),
    'project_name' => env('LOGTRACKER_PROJECT_NAME'),
    'api_key'      => env('LOGTRACKER_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Environment
    | Falls back to APP_ENV if LOGTRACKER_ENV is not set.
    |--------------------------------------------------------------------------
    */
    'environment' => env('LOGTRACKER_ENV', env('APP_ENV', 'production')),

    /*
    |--------------------------------------------------------------------------
    | Log Levels to capture
    | Accepted values: debug, info, warning, error, critical
    |--------------------------------------------------------------------------
    */
    'log_levels' => ['error', 'critical', 'warning'],

    /*
    |--------------------------------------------------------------------------
    | Batch Size
    | Logs are buffered and sent in one HTTP request once this limit is reached,
    | or at the end of the request lifecycle.
    |--------------------------------------------------------------------------
    */
    'batch_size' => 50,

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    | When true, an invalid or rejected API key throws an Exception immediately
    | instead of silently disabling the client. NEVER set to true in production.
    |--------------------------------------------------------------------------
    */
    'debug' => env('LOGTRACKER_DEBUG', false),
];
