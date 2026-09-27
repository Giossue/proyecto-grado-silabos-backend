<?php

return [
    'enabled' => env('CURRICULAR_EXTRACTION_ENABLED', env('AI_SOURCE_NORMALIZATION_ENABLED', false)),
    'base_url' => env('CURRICULAR_EXTRACTION_BASE_URL', env('AI_SOURCE_NORMALIZATION_BASE_URL', 'https://api.deepseek.com')),
    'model' => env('CURRICULAR_EXTRACTION_MODEL', env('AI_SOURCE_NORMALIZATION_MODEL', 'deepseek-flash')),
    'api_key' => env('CURRICULAR_EXTRACTION_API_KEY', env('AI_SOURCE_NORMALIZATION_API_KEY')),
    'timeout' => 80,
    'batch_characters' => 45000,
    'max_output_tokens' => 16000,
    'max_response_bytes' => 2097152,
    'max_files' => 10,
    'max_file_kilobytes' => 20480,
];
