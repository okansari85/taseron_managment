<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of service credentials.
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
        'text_model' => env('NVIDIA_NIM_TEXT_MODEL', 'nvidia/nemotron-3.5-lightning-30b-a3b'),
    ],

    // Google Gemini Interactions API — yangın tesisatı rapor analizinde
    // alternatif AI sağlayıcısı. Gemini yapılandırması ve client'ı aynen korunur.
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'text_model' => env('GEMINI_TEXT_MODEL', 'gemini-3.6-flash'),
    ],

    // Fire suppression semantic AI provider.
    // Şimdilik NVIDIA kullanılır; Gemini aboneliği açıldığında yalnızca
    // FIRE_SUPPRESSION_AI_PROVIDER=gemini yapılarak geri dönülebilir.
    'fire_suppression' => [
        'ai_provider' => env('FIRE_SUPPRESSION_AI_PROVIDER', 'nvidia'),
    ],

    // pktakip: tanıtım sitesinden ücretli paket kaydı, PayTR iFrame ödemesi. Anahtarlar yoksa ödeme kapalı (503).
    // landing_url: ödeme sonrası dönüş sayfaları (…/odeme-basarili, …/odeme-basarisiz).
    'paytr' => [
        'merchant_id' => env('PAYTR_MERCHANT_ID'),
        'merchant_key' => env('PAYTR_MERCHANT_KEY'),
        'merchant_salt' => env('PAYTR_MERCHANT_SALT'),
        'test_mode' => filter_var(env('PAYTR_TEST_MODE', false), FILTER_VALIDATE_BOOL),
        'landing_url' => env('PK_LANDING_URL') ?: 'https://pktakip.com',
        // Uygulama içi ödemede PayTR dönüş adresi (Paketim → ödeme sonucu).
        'app_url' => env('PAYTR_APP_URL') ?: 'https://app.pktakip.com',
        // Tanıtım sitesinden ödemeli kayıt (pk-signup/paytr/start); kapalıyken satın alma yalnız uygulama içinden.
        'signup_enabled' => filter_var(env('PAYTR_SIGNUP_ENABLED', false), FILTER_VALIDATE_BOOL),
        // Tanıtım sitesindeki Mesafeli Satış Sözleşmesi / Ön Bilgilendirme Formu metninin sürümü; metin değişince güncellenir.
        'agreements_version' => '2026-11-07',
    ],

];
