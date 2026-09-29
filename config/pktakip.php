<?php

// pktakip uzman paneli ayarları.
return [
    // Ekipman kataloğunu (teknik özellikler) değiştirebilen kullanıcıların e-postaları, virgülle ayrılmış.
    // super-admin rolündekiler her zaman yetkilidir.
    'catalog_admins' => env('PKTAKIP_CATALOG_ADMINS', ''),

    // Rapor okuma için yapay zeka: gemini | openai | nvidia. İstem ve çıktı şeması hepsinde aynı.
    'ai_provider' => env('PKTAKIP_AI_PROVIDER', 'gemini'),
    // Taranmış / görüntü PDF: yalnızca tek ekipman raporu, en fazla bu kadar sayfa, PDF'in kendisi yapay zekaya
    // gönderilir (şimdilik yalnızca OpenAI). Tesisat raporları ve daha uzun görüntü PDF'ler okunmaz.
    'image_max_pages' => (int) env('PKTAKIP_IMAGE_MAX_PAGES', 10),
    // Taranmış PDF → OCR ile "dijital ikiz" PDF (scripts/pk_ocr_twin.py: OpenCV hücreler + Tesseract tur), sonra
    // normal hat (yapay zeka metni + Camelot tabloları). Tesseract yolu / dil verisi boşsa betik kendisi bulur
    // (PATH, Windows varsayılan kurulum, ~/tessdata). Görüntüden okuma yalnızca OCR yapılamazsa devreye girer.
    'ocr' => [
        'enabled' => (bool) env('PKTAKIP_OCR', true),
        'tesseract' => env('PKTAKIP_TESSERACT'),
        'tessdata' => env('PKTAKIP_TESSDATA'),
        'max_pages' => (int) env('PKTAKIP_OCR_MAX_PAGES', 30),
        'timeout' => (int) env('PKTAKIP_OCR_TIMEOUT', 600),
    ],
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('PKTAKIP_OPENAI_MODEL', 'gpt-6-luna'),
        // Modelin desteklediği düşünme düzeyi (none | low | medium | high ...); boş bırakılırsa gönderilmez.
        'reasoning_effort' => env('PKTAKIP_OPENAI_REASONING', 'low'),
    ],
    // NVIDIA NIM (ücretsiz build.nvidia.com): anahtar ve adres taseron'daki ayardan (NVIDIA_NIM_API_KEY, NVIDIA_NIM_BASE_URL).
    'nvidia' => [
        // Boşsa NVIDIA_NIM_TEXT_MODEL kullanılır.
        'model' => env('PKTAKIP_NVIDIA_MODEL'),
        'max_tokens' => (int) env('PKTAKIP_NVIDIA_MAX_TOKENS', 16000),
        // Şema zorlaması (nvext.guided_json); model desteklemiyorsa false: yalnızca JSON istenir.
        'guided_json' => (bool) env('PKTAKIP_NVIDIA_GUIDED_JSON', true),
    ],
];
