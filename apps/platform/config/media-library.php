<?php

$driver = env('MEDIA_STORAGE_DRIVER', 'local');

return [
    /*
     * Media Library stores the concrete disk name on every media row. Keeping
     * local and object-storage disks distinct lets existing files remain
     * readable while data is migrated between backends.
     */
    'disk_name' => $driver === 's3' ? 'media-s3' : 'media-local',

    /*
     * Large app-icon and splash-screen conversion sets should not inherit a
     * synchronous queue used by tests or one-off commands. Production keeps
     * using the application's queue connection unless explicitly overridden.
     */
    'queue_connection_name' => env('MEDIA_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'sync')),
    'queue_name' => env('MEDIA_QUEUE', 'default'),

    /*
     * App file attachments may contain any file type. Keep the size limit
     * configurable so hosted and self-hosted installations can choose an
     * appropriate ceiling for their storage and PHP upload configuration.
     */
    'max_file_size_kb' => (int) env('MEDIA_MAX_FILE_SIZE_KB', 102400),

    /*
     * Spatie Media Library reads `max_file_size` (bytes) when attaching files.
     * Without this key the package default of 10 MB applies, which rejects
     * ordinary mobile build artifacts. Keep it in lockstep with the KB limit.
     */
    'max_file_size' => ((int) env('MEDIA_MAX_FILE_SIZE_KB', 102400)) * 1024,
];
