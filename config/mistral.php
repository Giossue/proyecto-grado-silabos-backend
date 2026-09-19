<?php

return [
    'enabled' => (bool) env('MISTRAL_OCR_ENABLED', false),
    'base_url' => rtrim((string) env('MISTRAL_BASE_URL', 'https://api.mistral.ai/v1'), '/'),
    'model' => env('MISTRAL_OCR_MODEL', 'mistral-ocr-4-1'),
    'api_key' => env('MISTRAL_API_KEY'),
    'adapter_version' => 'mistral-ocr-http-v1',
    'connect_timeout_seconds' => (int) env('MISTRAL_OCR_CONNECT_TIMEOUT', 5),
    'timeout_seconds' => (int) env('MISTRAL_OCR_TIMEOUT', 180),
    'max_upload_bytes' => (int) env('MISTRAL_OCR_MAX_UPLOAD_BYTES', 52428800),
    'max_response_bytes' => (int) env('MISTRAL_OCR_MAX_RESPONSE_BYTES', 20971520),
    'table_format' => 'markdown',
    'include_blocks' => true,
    'confidence_scores_granularity' => 'block',
    'include_image_base64' => false,
];
