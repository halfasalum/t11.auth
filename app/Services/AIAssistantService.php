<?php

namespace App\Services;


use OpenAI;

class AIAssistantService
{
    protected $client;

    public function __construct()
    {
        // Initialize OpenAI client correctly with latest SDK
        $this->client = OpenAI::client(env('OPENAI_API_KEY'));
    }

    /**
     * Generate AI explanation for a system failure
     *
     * @param string $errorCode Example: AUTH_INVALID_CREDENTIALS
     * @param string $userRole Example: Loan Officer
     * @param string $language 'en' or 'sw'
     * @return string AI-generated explanation
     */
    public function explainFailure(string $errorCode, string $userRole, string $language = 'en'): string
    {
        // Map your error codes to human-readable descriptions
        $errorDescriptions = [
            'AUTH_INVALID_CREDENTIALS' => 'Login failed due to invalid username or password.',
            'AUTH_ACCOUNT_LOCKED' => 'Account is temporarily locked due to multiple failed login attempts.',
            'AUTH_PASSWORD_EXPIRED' => 'Password has expired.',
            'AUTH_2FA_REQUIRED' => 'Two-factor authentication code is required.',
        ];

        $description = $errorDescriptions[$errorCode] ?? 'An unknown error occurred.';

        // Build prompt for AI
        $prompt = "
You are an enterprise system assistant.
Explain system failures clearly and professionally in {$language}.
Never blame the user.
Provide steps for both the user and the system administrator.

Error Code: {$errorCode}
Description: {$description}
User Role: {$userRole}

Explain why the action failed and what actions should be taken for the user and the administrator.
";

        // Call OpenAI GPT-4 model
        $response = $this->client->chat()->create([
            'model' => 'gpt-3.5-turbo', // <- use this instead of gpt-4
            'messages' => [
                ['role' => 'system', 'content' => 'You are a helpful system assistant.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => 50,
        ]);

        // Return AI explanation
        return $response->choices[0]->message->content ?? 'No explanation available.';
    }
}
