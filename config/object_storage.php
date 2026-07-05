<?php

return [

    /*
    |--------------------------------------------------------------------------
    | STORAGE-1 — Object Storage Readiness
    |--------------------------------------------------------------------------
    |
    | Foundation for a future S3-compatible / MinIO / AWS S3 object storage
    | disk. OFF by default: when disabled, nothing in the application touches
    | the "object" disk and no existing local/public file is moved or altered.
    |
    | Enabling this does not migrate any existing file. It only allows the
    | readiness command and future features to target the "object" disk.
    |
    */

    'enabled' => (bool) env('OBJECT_STORAGE_ENABLED', false),

    // Filesystem disk name (see config/filesystems.php "object" disk).
    'disk' => env('OBJECT_STORAGE_DISK', 'object'),

    // Env keys required when enabled. Never log or expose their values.
    'required_env' => [
        'OBJECT_STORAGE_BUCKET',
        'OBJECT_STORAGE_ACCESS_KEY_ID',
        'OBJECT_STORAGE_SECRET_ACCESS_KEY',
    ],

    // Prefix used for the non-destructive write/read/delete healthcheck object.
    'healthcheck_prefix' => env('OBJECT_STORAGE_HEALTHCHECK_PREFIX', 'healthchecks/daengtisiams'),

];
