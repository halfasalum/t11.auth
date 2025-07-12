<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'payment_gateway' => [
        'base_url' => env('PAYMENT_GATEWAY_BASE_URL', 'https://authenticator-sandbox.azampay.co.tz'),
        'app_name' => env('PAYMENT_GATEWAY_APP_NAME', 'TerminalXI'),
        'client_id' => env('PAYMENT_GATEWAY_CLIENT_ID', '98284298-ff61-48be-8358-fee1c8b32bd2'),
        'client_secret' => env('PAYMENT_GATEWAY_CLIENT_SECRET', 'LrKAIrFHZHvpCrtgNCLQ0XyTZpfz47ueaommxKzk0hzt8qAOuCuIiQhAqocUyhWSYLpBpOqb0nJ+jXHEBg+tU+0nbLAdrf92TOQw5zEoEL91h7fXam0rwefTBWSC4E4yKIPWHbTogSqh1O3sl/pCOvKOEFgJWCKHHsHFDPyYKtbQ6P7C6QOABVmw3HJHfhlMMWsgFPEq/CO6NKsTgqlpwFGRVSfSm7zGFK3Uetg5RncA1oLzBxr4ymhYDpwmF9tEY+CQJjxZY53EY1JnEl8Zn0nXdKKUopWcCVTbX6A2wXjgVo5BrIJRBBS3ONMYraRyGqkmBEiEtZaz/2Yyn0Bx1ldKHbZU5U7E6iZhpD5R5bOrGktt1ZORTeRSz1mDICjDnqm7vLx6YrMb2IfSvEWmw8Jtx2EMpIZtOCQI+P3sdnavm6TFpE44PyCNaWhLCIRGT0cqgWe1SP6nrnwgTF+iSG7QRmtcvy8leBJjnZ2KCKDfmEerL67HbwEBumgVUMO6PRmmgaKefvDEAiXZxZTl7vHmMk2Cb6Wx7CtjMd7CfSAkDM8RSHBJwStCZMOp0UscRpZiyMi2t/kIgxW+mzbD7FCNzJd/QrfjHMFtPu3GrOjRw9XjaWas55vTljbprJwHECxZqMhyia6TtZNpOOJpuL9PFsEZ2KTFbWUe+6iuj70='),
        'client_token' => env('PAYMENT_GATEWAY_CLIENT_TOKEN', '3b9d9d5e-0dca-45ff-8ecd-747c09e5c94c'),
    ],
   'airtel_money' => [
        'base_url' => env('AIRTEL_MONEY_BASE_URL', 'https://openapiuat.airtel.africa'),
        'client_id' => env('AIRTEL_MONEY_CLIENT_ID'),
        'client_secret' => env('AIRTEL_MONEY_CLIENT_SECRET'),
        'x_key' => env('AIRTEL_MONEY_X_KEY'),
        'x_signature' => env('AIRTEL_MONEY_X_SIGNATURE'),
        'public_key' => env('AIRTEL_MONEY_PUBLIC_KEY'),
    ],

];
