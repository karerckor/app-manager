<?php

return [
    'enabled' => env('INTEGRATIONS_ENABLED', true),

    'github' => [
        'enabled' => env('GITHUB_INTEGRATION_ENABLED', true),
        'app_id' => env('GITHUB_APP_ID'),
        'client_id' => env('GITHUB_APP_CLIENT_ID'),
        'client_secret' => env('GITHUB_APP_CLIENT_SECRET'),
        'slug' => env('GITHUB_APP_SLUG'),
        'private_key' => env('GITHUB_APP_PRIVATE_KEY'),
        'webhook_secret' => env('GITHUB_APP_WEBHOOK_SECRET'),
        'api_url' => env('GITHUB_API_URL', 'https://api.github.com'),
        'web_url' => env('GITHUB_WEB_URL', 'https://github.com'),
        'api_version' => env('GITHUB_API_VERSION', '2026-03-10'),
        'connection_state_ttl_seconds' => (int) env('GITHUB_CONNECTION_STATE_TTL', 600),
    ],

    'webhooks' => [
        'enabled' => env('OUTBOUND_WEBHOOKS_ENABLED', true),
    ],
];
