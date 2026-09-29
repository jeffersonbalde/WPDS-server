<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | "public" holds avatars and other browser-visible uploads.
    | - Local: files under storage/app/public (served via public/storage link)
    | - Spaces/S3: when AWS_ACCESS_KEY_ID is set. root MUST be a bucket-relative
    |   prefix (usually empty), never a local filesystem path — otherwise keys
    |   become /workspace/storage/app/public/... and URLs 403 on DigitalOcean.
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => array_filter([
            'driver' => env('AWS_ACCESS_KEY_ID') ? 's3' : 'local',
            // S3/Spaces: empty root (or AWS_ROOT_PATH prefix). Local: storage/app/public.
            'root' => env('AWS_ACCESS_KEY_ID')
                ? (string) env('AWS_ROOT_PATH', '')
                : storage_path('app/public'),
            'url' => env('AWS_URL')
                ?: (env('AWS_ACCESS_KEY_ID')
                    ? null
                    : rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage'),
            'visibility' => 'public',
            'directory_visibility' => 'public',
            'throw' => false,
            'report' => false,
            'key' => env('AWS_ACCESS_KEY_ID') ?: null,
            'secret' => env('AWS_SECRET_ACCESS_KEY') ?: null,
            'region' => env('AWS_DEFAULT_REGION') ?: null,
            'bucket' => env('AWS_BUCKET') ?: null,
            'endpoint' => env('AWS_ENDPOINT') ?: null,
            'use_path_style_endpoint' => filter_var(
                env('AWS_USE_PATH_STYLE_ENDPOINT', false),
                FILTER_VALIDATE_BOOLEAN
            ),
            'options' => env('AWS_ACCESS_KEY_ID') ? [
                'ACL' => 'public-read',
            ] : null,
        ], static fn ($value) => $value !== null),

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
