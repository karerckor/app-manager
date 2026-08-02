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

    'gitlab' => [
        'enabled' => env('GITLAB_INTEGRATION_ENABLED', false),
        'self_hosted_enabled' => env('GITLAB_SELF_HOSTED_ENABLED', true),
        'client_id' => env('GITLAB_OAUTH_CLIENT_ID'),
        'client_secret' => env('GITLAB_OAUTH_CLIENT_SECRET'),
        'api_url' => env('GITLAB_API_URL', 'https://gitlab.com/api/v4'),
        'web_url' => env('GITLAB_WEB_URL', 'https://gitlab.com'),
        'scopes' => array_values(array_filter(array_map('trim', explode(',', env('GITLAB_OAUTH_SCOPES', 'api,openid'))))),
        'connection_state_ttl_seconds' => (int) env('GITLAB_CONNECTION_STATE_TTL', 600),
    ],

    'expo' => [
        'enabled' => env('EXPO_INTEGRATION_ENABLED', false),
        'api_url' => env('EXPO_API_URL', 'https://api.expo.dev'),
        'graphql_url' => env('EXPO_GRAPHQL_URL', 'https://api.expo.dev/graphql'),
        'web_url' => env('EXPO_WEB_URL', 'https://expo.dev'),
        'timeout_seconds' => (int) env('EXPO_REQUEST_TIMEOUT', 30),
    ],

    'webhooks' => [
        'enabled' => env('OUTBOUND_WEBHOOKS_ENABLED', true),
    ],

    'notifications' => [
        'enabled' => env('NOTIFICATION_INTEGRATIONS_ENABLED', true),
        'timeout_seconds' => (int) env('NOTIFICATION_REQUEST_TIMEOUT', 10),
        'connect_timeout_seconds' => (int) env('NOTIFICATION_CONNECT_TIMEOUT', 3),
        'telegram_api_url' => env('TELEGRAM_API_URL', 'https://api.telegram.org'),
        'retry_delays' => [60, 300, 1800],
    ],
];
