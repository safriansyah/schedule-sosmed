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
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            // Separate from APP_URL on purpose.
            //
            // Instagram does not receive the image file — it receives this URL
            // and its own servers fetch it, so it must be reachable from the
            // internet. On an intranet deployment APP_URL is a private address
            // and never can be.
            //
            // Splitting them means a tunnel can expose ONLY /storage on a public
            // hostname while the application itself stays on the office network.
            // Leave FILESYSTEM_PUBLIC_URL empty and it falls back to APP_URL,
            // which is right for a normal public deployment.
            //
            // rtrim on both: APP_URL written as "https://example.id/" is a
            // perfectly reasonable thing to type, and concatenating it produced
            // "https://example.id//storage/foto.jpg". A browser forgives the
            // double slash; a server-side fetcher asking for that exact path
            // does not have to, and Instagram's is the one that matters here.
            'url' => rtrim(env('FILESYSTEM_PUBLIC_URL') ?: rtrim((string) env('APP_URL'), '/').'/storage', '/'),
            'visibility' => 'public',
            'throw' => false,
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
