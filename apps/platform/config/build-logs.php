<?php

return [
    'retention_days' => (int) env('BUILD_LOG_RETENTION_DAYS', 30),
    'archive_retention_days' => (int) env('BUILD_LOG_ARCHIVE_RETENTION_DAYS', 365),
];
