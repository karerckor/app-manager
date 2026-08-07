<?php

return [
    /* Full event payloads remain queryable in PostgreSQL during the hot window. */
    'hot_retention_days' => (int) env('SENTRY_HOT_RETENTION_DAYS', 30),

    /* Compressed cold archives remain downloadable for at least one year. */
    'archive_retention_days' => (int) env('SENTRY_ARCHIVE_RETENTION_DAYS', 365),

    'disk' => env('SENTRY_ARCHIVE_DISK'),
];
