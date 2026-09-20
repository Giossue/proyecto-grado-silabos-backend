<?php

return [
    'enabled' => (bool) env('PADDLEOCR_ENABLED', false),
    'base_url' => rtrim((string) env('PADDLEOCR_BASE_URL', 'https://paddleocr.aistudio-app.com'), '/'),
    'access_token' => env('PADDLEOCR_ACCESS_TOKEN'),
    'model' => env('PADDLEOCR_MODEL', 'PaddleOCR-VL-1.6'),
    'adapter_version' => 'paddleocr-official-api-v1',
    'connect_timeout_seconds' => (int) env('PADDLEOCR_CONNECT_TIMEOUT', 5),
    'timeout_seconds' => (int) env('PADDLEOCR_TIMEOUT', 120),
    'poll_delay_seconds' => (int) env('PADDLEOCR_POLL_DELAY', 5),
    'max_poll_minutes' => (int) env('PADDLEOCR_MAX_POLL_MINUTES', 20),
    'max_upload_bytes' => (int) env('PADDLEOCR_MAX_UPLOAD_BYTES', 52428800),
    'max_response_bytes' => (int) env('PADDLEOCR_MAX_RESPONSE_BYTES', 52428800),
    'max_image_bytes' => (int) env('PADDLEOCR_MAX_IMAGE_BYTES', 10485760),
    'options' => [
        'useLayoutDetection' => true,
        'useChartRecognition' => true,
        'useOcrForImageBlock' => true,
        'formatBlockContent' => true,
        'prettifyMarkdown' => true,
        'restructurePages' => false,
        'mergeTables' => false,
        'relevelTitles' => true,
        'returnMarkdownImages' => true,
    ],
];
