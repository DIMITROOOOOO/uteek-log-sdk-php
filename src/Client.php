<?php

/**
 * @deprecated Use LogTrackerClient instead. This class exists for backward compatibility only.
 */
class Client extends LogTrackerClient
{
    public function __construct(array $config)
    {
        // Map legacy 'ingest_url' key to the new 'api_url' key.
        if (isset($config['ingest_url']) && !isset($config['api_url'])) {
            $config['api_url'] = rtrim(str_replace('/ingest', '', $config['ingest_url']), '/');
        }

        parent::__construct($config);
    }
}