<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Private media storage
    |--------------------------------------------------------------------------
    |
    | The hosted distribution reads these values from the environment. The
    | self-hosted installer stores an encrypted override in the installations
    | table and applies it at runtime.
    |
    | Supported drivers: "local", "s3"
    |
    */
    'driver' => env('MEDIA_STORAGE_DRIVER', 'local'),

    'disks' => [
        'local' => 'media-local',
        's3' => 'media-s3',
    ],

    'local' => [
        'root' => env('MEDIA_LOCAL_ROOT') ?: storage_path('app/private'),
    ],

    's3' => [
        'access_key_id' => env('MEDIA_S3_ACCESS_KEY_ID'),
        'secret_access_key' => env('MEDIA_S3_SECRET_ACCESS_KEY'),
        'session_token' => env('MEDIA_S3_SESSION_TOKEN'),
        'region' => env('MEDIA_S3_REGION', 'us-east-1'),
        'bucket' => env('MEDIA_S3_BUCKET'),
        'endpoint' => env('MEDIA_S3_ENDPOINT'),
        'url' => env('MEDIA_S3_URL'),
        'prefix' => env('MEDIA_S3_PREFIX'),
        'use_path_style_endpoint' => env('MEDIA_S3_USE_PATH_STYLE_ENDPOINT', false),
        'object_acl' => env('MEDIA_S3_OBJECT_ACL', 'bucket-owner-full-control'),
        'server_side_encryption' => env('MEDIA_S3_SERVER_SIDE_ENCRYPTION'),
        'kms_key_id' => env('MEDIA_S3_KMS_KEY_ID'),
    ],
];
