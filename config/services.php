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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Yangın Söndürme Sistemleri / YSC rapor PDF'lerinin AI destekli okunması
    // için — ücretsiz build.nvidia.com (NVIDIA NIM) hesabından alınan API
    // anahtarı. Anahtar yoksa AI analizi devre dışı kalır, elle giriş akışı
    // (mevcut) etkilenmez.
    'nvidia_nim' => [
        'api_key' => env('NVIDIA_NIM_API_KEY'),
        'base_url' => env('NVIDIA_NIM_BASE_URL', 'https://integrate.api.nvidia.com/v1'),
        'ocr_model' => env('NVIDIA_NIM_OCR_MODEL', 'nvidia/nemotron-ocr-v2'),
        // meta/llama-3.1-8b-instruct VE meta/llama-3.3-70b-instruct NVIDIA
        // NIM'de 2026-08-26'da EOL oldu (HTTP 410). nvidia/nemotron-3.5-
        // lightning-30b-a3b canlı ve JSON çıktısı için test edilip
        // doğrulandı (bkz. NvidiaNimClient — "thinking" kapatılıyor, aksi
        // halde reasoning model chain-of-thought metniyle JSON'ı kirletiyor).
        'text_model' => env('NVIDIA_NIM_TEXT_MODEL', 'nvidia/nemotron-3.5-lightning-30b-a3b'),
    ],

];
