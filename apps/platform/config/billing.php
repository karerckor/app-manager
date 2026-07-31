<?php

$distribution = env('APP_DISTRIBUTION', 'self-hosted');
$mode = env('BILLING_MODE', 'off');

return [
    'distribution' => $distribution,
    'mode' => $mode,
    'enabled' => $distribution === 'hosted' && $mode !== 'off',

    'subscription' => 'default',
    'queue' => env('BILLING_QUEUE', 'billing'),

    'prices' => [
        'base_monthly' => env('STRIPE_PRICE_BASE_MONTHLY'),
        'build_runs' => env('STRIPE_PRICE_BUILD_RUNS'),
    ],

    'meters' => [
        'build_runs' => [
            'id' => env('STRIPE_METER_BUILD_RUNS_ID'),
            'event_name' => env('STRIPE_METER_BUILD_RUNS_EVENT', 'app_manager_build_runs'),
        ],
    ],
];
