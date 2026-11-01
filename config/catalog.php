<?php

return [
    'driver' => env('CATALOG_DRIVER', 'disabled'),
    'koha' => [
        'base_url' => env('KOHA_BASE_URL'),
        'connect_timeout_seconds' => (int) env('KOHA_CONNECT_TIMEOUT', 3),
        'timeout_seconds' => (int) env('KOHA_TIMEOUT', 10),
        'max_results' => 10,
        'response_bytes' => 524288,
    ],
    'limits' => [
        'requests_per_minute' => 30,
        'term_characters' => 120,
    ],
];
