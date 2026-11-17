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
    'template' => [
        'model' => env('AI_TEMPLATE_MODEL'),
        'strict_tools' => (bool) env('AI_TOOLS_STRICT', true),
        'temperature' => env('AI_TEMPERATURE', 0.0),
        'max_output_tokens' => 8192,
        'max_steps' => 8,
        'max_seconds' => 120,
        'max_consecutive_errors' => 3,
        'retry_delays_ms' => [2000, 5000],
    ],
    'limits' => [
        'response_bytes' => 262144,
        'chat_message_characters' => 4000,
    ],
];
