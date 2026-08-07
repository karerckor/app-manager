<?php

return [
    /* Recoverable deletion window for applications and other domain records. */
    'trash_retention_days' => (int) env('TRASH_RETENTION_DAYS', 90),

    /* Credentials use the same safe default but can be configured independently. */
    'credential_trash_retention_days' => (int) env(
        'CREDENTIAL_TRASH_RETENTION_DAYS',
        env('TRASH_RETENTION_DAYS', 90),
    ),
];
