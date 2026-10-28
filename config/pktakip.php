<?php

// pktakip uzman paneli ayarları.
return [
    // Ekipman kataloğunu (teknik özellikler) değiştirebilen kullanıcıların e-postaları, virgülle ayrılmış.
    // super-admin rolündekiler her zaman yetkilidir.
    'catalog_admins' => env('PKTAKIP_CATALOG_ADMINS', ''),

    // Kısa talimatlar (PkShortPromptRouter): tek ekipman raporu kendi kısa talimatıyla, tür bilinmiyorsa önce tür bulma;
    // metin smalot yerine önce pdfium ile (smalot bazı PDF'lerde boşlukları yutuyor). Kapalıyken mevcut akış aynen.
    'short_prompts' => (bool) env('PKTAKIP_SHORT_PROMPTS', false),
    // Kısa talimatlar açıkken tesisat raporları yapısı korunarak (başlık, tablo, liste) Docling ile okunur
    // (scripts/pk_docling.py); Docling'in kurulu olduğu Python. Boşsa pdfium düz metni.
    'docling_python' => env('PKTAKIP_DOCLING_PYTHON'),
    // Sürekli açık Docling servisi (scripts/pk_docling.py --serve <port>); doluysa betik yerine bu kullanılır (model her
    // raporda yeniden yüklenmez).
    'docling_url' => env('PKTAKIP_DOCLING_URL'),
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
    // Yerel sunucu (vLLM / Ollama / LM Studio, OpenAI uyumlu chat/completions): kurumun kendi makinesi, veri dışarı çıkmaz.
    'local' => [
        // Örn. http://10.0.0.5:8000/v1 (vLLM) ya da http://localhost:11434/v1 (Ollama).
        'base_url' => env('PKTAKIP_LOCAL_BASE_URL'),
        'api_key' => env('PKTAKIP_LOCAL_API_KEY'),
        // Sunucudaki model adı: vLLM'de openai/gpt-oss-20b, Ollama'da gpt-oss:20b.
        'model' => env('PKTAKIP_LOCAL_MODEL', 'openai/gpt-oss-20b'),
        'max_tokens' => (int) env('PKTAKIP_LOCAL_MAX_TOKENS', 16000),
        // gpt-oss gibi düşünen modellerde düzey (low | medium | high); boşsa gönderilmez.
        'reasoning_effort' => env('PKTAKIP_LOCAL_REASONING', 'low'),
        // Yerel kart bulut kadar hızlı olmayabilir.
        'timeout' => (int) env('PKTAKIP_LOCAL_TIMEOUT', 600),
        // Şema zorlaması (response_format json_schema); sunucu desteklemiyorsa false: yalnızca JSON istenir.
        'json_schema' => (bool) env('PKTAKIP_LOCAL_JSON_SCHEMA', true),
        // Kendinden imzalı sertifika (örn. Vast.ai denemesi) için false; kurumun kendi ağında http ya da geçerli sertifika.
        'verify_ssl' => (bool) env('PKTAKIP_LOCAL_VERIFY_SSL', true),
        // Okuma modeli: raporu sayfa sayfa okuyup bölümlere ayıran ajan (PkPageSections) için genel amaçlı model (örn. Qwen3.5-4B);
        // çıkarma işleri yukarıdaki modelde kalır. Boşsa bölüm haritası çıkarılmaz, Docling'in bölümleri kullanılır.
        'reader_url' => env('PKTAKIP_LOCAL_READER_URL'),
        'reader_model' => env('PKTAKIP_LOCAL_READER_MODEL'),
    ],
];
