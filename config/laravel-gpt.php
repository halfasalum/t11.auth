<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OpenAI API Key
    |--------------------------------------------------------------------------
    |
    | Your OpenAI API key. You can obtain one from:
    | https://platform.openai.com/api-keys
    |
    */
    'api_key' => env('OPENAI_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Default Model
    |--------------------------------------------------------------------------
    |
    | The default ChatGPT model to use when no model is specified.
    | Available models include: gpt-3.5-turbo, gpt-4, gpt-4-turbo-preview
    |
    */
    'default_model' => env('OPENAI_DEFAULT_MODEL', 'gpt-3.5-turbo'),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | The timeout in seconds for API requests.
    |
    */
    'timeout' => env('OPENAI_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Max Retries
    |--------------------------------------------------------------------------
    |
    | Maximum number of retry attempts for failed requests.
    |
    */
    'max_retries' => env('OPENAI_MAX_RETRIES', 3),

    /*
    |--------------------------------------------------------------------------
    | Retry Delay
    |--------------------------------------------------------------------------
    |
    | Delay in seconds between retry attempts.
    |
    */
    'retry_delay' => env('OPENAI_RETRY_DELAY', 1),

    /*
    |--------------------------------------------------------------------------
    | Base URL
    |--------------------------------------------------------------------------
    |
    | The base URL for the OpenAI API. Usually only changed for testing
    | or if using a proxy.
    |
    */
    'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),

    /*
    |--------------------------------------------------------------------------
    | Organization
    |--------------------------------------------------------------------------
    |
    | Optional OpenAI organization ID for usage tracking.
    |
    */
    'organization' => env('OPENAI_ORGANIZATION'),
];
