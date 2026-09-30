<?php

return [
    'driver' => env('AI_DRIVER', 'disabled'),
    'hosted' => [
        'base_url' => env('AI_BASE_URL'),
        'model' => env('AI_MODEL'),
        'api_key' => env('AI_API_KEY'),
        'connect_timeout_seconds' => (int) env('AI_CONNECT_TIMEOUT', 2),
        'timeout_seconds' => (int) env('AI_TIMEOUT', 20),
        'max_output_tokens' => 4096,
    ],
    'source_normalization' => [
        'enabled' => env('AI_SOURCE_NORMALIZATION_ENABLED', false),
        'driver' => env('AI_SOURCE_NORMALIZATION_DRIVER', 'deepseek'),
        'base_url' => env('AI_SOURCE_NORMALIZATION_BASE_URL', 'https://api.deepseek.com'),
        'model' => env('AI_SOURCE_NORMALIZATION_MODEL', 'deepseek-flash'),
        'api_key' => env('AI_SOURCE_NORMALIZATION_API_KEY'),
        'connect_timeout_seconds' => (int) env('AI_SOURCE_NORMALIZATION_CONNECT_TIMEOUT', 5),
        'timeout_seconds' => (int) env('AI_SOURCE_NORMALIZATION_TIMEOUT', 120),
        'batch_input_characters' => (int) env('AI_SOURCE_NORMALIZATION_BATCH_INPUT_CHARACTERS', 100000),
        'max_output_tokens' => (int) env('AI_SOURCE_NORMALIZATION_MAX_OUTPUT_TOKENS', 20000),
        'max_response_bytes' => (int) env('AI_SOURCE_NORMALIZATION_MAX_RESPONSE_BYTES', 4194304),
        'max_page_output_characters' => (int) env('AI_SOURCE_NORMALIZATION_MAX_PAGE_OUTPUT_CHARACTERS', 200000),
        'max_image_bytes' => (int) env('AI_SOURCE_NORMALIZATION_MAX_IMAGE_BYTES', 2097152),
    ],
    'limits' => [
        'evidence_items' => 50,
        'evidence_excerpt_characters' => 12000,
        'response_bytes' => 262144,
        'requests_per_minute' => 6,
        'chat_message_characters' => 4000,
        'chat_answer_characters' => 12000,
        'chat_history_messages' => 12,
    ],
];
