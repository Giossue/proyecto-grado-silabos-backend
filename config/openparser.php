<?php

return [
    'enabled' => (bool) env('OPENPARSER_ENABLED', false),
    'base_url' => rtrim((string) env('OPENPARSER_BASE_URL', 'https://api.openparser.dev'), '/'),
    'api_key' => env('OPENPARSER_API_KEY'),
    'ocr_model' => env('OPENPARSER_OCR_MODEL', 'paddleocr-vl-1.6'),
    'output_format' => 'openparser@1',
    'adapter_version' => 'openparser-v1',
    'connect_timeout_seconds' => (int) env('OPENPARSER_CONNECT_TIMEOUT', 5),
    'timeout_seconds' => (int) env('OPENPARSER_TIMEOUT', 120),
    'poll_delay_seconds' => (int) env('OPENPARSER_POLL_DELAY', 5),
    'max_poll_minutes' => (int) env('OPENPARSER_MAX_POLL_MINUTES', 20),
    'max_upload_bytes' => (int) env('OPENPARSER_MAX_UPLOAD_BYTES', 52428800),
    'max_response_bytes' => (int) env('OPENPARSER_MAX_RESPONSE_BYTES', 52428800),
    'max_image_bytes' => (int) env('OPENPARSER_MAX_IMAGE_BYTES', 10485760),
    'figure_render_dpi' => (int) env('OPENPARSER_FIGURE_RENDER_DPI', 144),
    'figure_render_timeout_seconds' => (int) env('OPENPARSER_FIGURE_RENDER_TIMEOUT', 60),
    'allowed_asset_hosts' => array_values(array_filter(array_map(
        static fn (string $host): string => strtolower(trim($host)),
        explode(',', (string) env('OPENPARSER_ALLOWED_ASSET_HOSTS', 'api.openparser.dev')),
    ))),
];
