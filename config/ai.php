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
