<?php

return [
    'allow_private_addresses' => env('WEBHOOKS_ALLOW_PRIVATE_ADDRESSES', false),
    'connect_timeout' => (int) env('WEBHOOKS_CONNECT_TIMEOUT', 5),
    'timeout' => (int) env('WEBHOOKS_TIMEOUT', 10),
    'response_body_limit' => 4096,
    'retry_delays' => [60, 300, 1800, 7200, 43200, 86400],
];
