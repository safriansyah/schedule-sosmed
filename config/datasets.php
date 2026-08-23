<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Upload
    |--------------------------------------------------------------------------
    | Maximum accepted JSON upload size (kilobytes) and the disk used to keep
    | the raw source file so a dataset can be re-imported / replaced.
    */
    'max_upload_kb' => (int) env('DATASET_MAX_UPLOAD_KB', 102400),
    'source_disk' => env('DATASET_SOURCE_DISK', 'local'),
    'source_dir' => 'dataset-sources',

    /*
    |--------------------------------------------------------------------------
    | Import processing
    |--------------------------------------------------------------------------
    | Rows flushed to the database per bulk insert. Tuned for memory vs. speed
    | on large files (5k–100k+ records). `queued` runs the parse on the queue.
    */
    'import_chunk' => (int) env('DATASET_IMPORT_CHUNK', 1000),
    'queued' => (bool) env('DATASET_IMPORT_QUEUED', true),

    /*
    |--------------------------------------------------------------------------
    | Analytics cache
    |--------------------------------------------------------------------------
    | Computed analytics are expensive on large datasets, so they are cached
    | and invalidated whenever the dataset's items change.
    */
    'analytics_ttl' => (int) env('ANALYTICS_CACHE_TTL', 900),

    /*
    |--------------------------------------------------------------------------
    | Server-side table
    |--------------------------------------------------------------------------
    */
    'per_page' => 25,
    'per_page_options' => [25, 50, 100, 250],

    /*
    |--------------------------------------------------------------------------
    | Followers buckets used across analytics
    |--------------------------------------------------------------------------
    */
    'follower_buckets' => [
        ['label' => '1K – 5K', 'min' => 1000, 'max' => 4999],
        ['label' => '5K – 10K', 'min' => 5000, 'max' => 9999],
        ['label' => '10K – 50K', 'min' => 10000, 'max' => 49999],
        ['label' => '50K – 100K', 'min' => 50000, 'max' => 99999],
        ['label' => '100K+', 'min' => 100000, 'max' => null],
    ],
];
