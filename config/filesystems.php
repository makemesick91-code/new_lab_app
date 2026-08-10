<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
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

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // STORAGE-1 — object storage readiness (S3-compatible / MinIO / AWS S3).
        // OFF by default; only used when OBJECT_STORAGE_ENABLED=true (see config/object_storage.php).
        // No existing file is moved here; this disk is additive foundation only.
        'object' => [
            'driver' => env('OBJECT_STORAGE_DRIVER', 's3'),
            'key' => env('OBJECT_STORAGE_ACCESS_KEY_ID'),
            'secret' => env('OBJECT_STORAGE_SECRET_ACCESS_KEY'),
            'region' => env('OBJECT_STORAGE_REGION', 'auto'),
            'bucket' => env('OBJECT_STORAGE_BUCKET'),
            'url' => env('OBJECT_STORAGE_URL'),
            'endpoint' => env('OBJECT_STORAGE_ENDPOINT'),
            'use_path_style_endpoint' => env('OBJECT_STORAGE_USE_PATH_STYLE_ENDPOINT', true),
            'throw' => env('OBJECT_STORAGE_THROW', true),
            'report' => false,
            'visibility' => 'private',
        ],

        // LEGACY-RME-PDF-1B — private storage for legacy RME archive documents
        // (source PDFs, rendered page images, thumbnails).
        //
        // Deliberately rooted OUTSIDE storage/app/private (the `local` disk
        // root) and with `serve` disabled: the framework registers a
        // `storage.local` route for the `local` disk, so any file living under
        // its root could in principle be served by a signed framework URL.
        // Clinical archive pages must only ever be reachable through the
        // policy-gated streaming route, so they get their own root that no
        // framework route can address.
        'legacy_rme_private' => [
            'driver' => 'local',
            'root' => storage_path('app/legacy-rme-private'),
            'serve' => false,
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
