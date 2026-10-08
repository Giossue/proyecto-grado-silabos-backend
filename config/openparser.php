<?php

return [
    'enabled' => (bool) env('OPENPARSER_ENABLED', false),
    'base_url' => rtrim((string) env('OPENPARSER_BASE_URL', 'https://api.openparser.dev'), '/'),
    'api_key' => env('OPENPARSER_API_KEY'),
    'ocr_model' => env('OPENPARSER_OCR_MODEL', 'paddleocr-vl-1.6'),
    'output_format' => 'openparser@1',
    'connect_timeout_seconds' => (int) env('OPENPARSER_CONNECT_TIMEOUT', 5),
    'timeout_seconds' => (int) env('OPENPARSER_TIMEOUT', 120),
    'poll_delay_seconds' => (int) env('OPENPARSER_POLL_DELAY', 5),
    'max_wait_seconds' => (int) env('OPENPARSER_MAX_WAIT', 780),
];
