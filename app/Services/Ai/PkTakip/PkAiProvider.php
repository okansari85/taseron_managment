<?php

namespace App\Services\Ai\PkTakip;

use App\Models\PkSetting;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;

/**
 * pktakip rapor okuyan yapay zeka: sağlayıcı (gemini | openai | nvidia), her sağlayıcının modeli ve API anahtarı.
 * Ayarlar sayfasından kaydedilen değer (pk_settings, anahtarlar şifreli) önce gelir; yoksa .env / config.
 * İstem ve çıktı şeması hepsinde aynıdır; yalnızca istemci değişir. Taseron'un kendi yapay zeka özellikleri
 * (.env'deki anahtarlarla) bu ayardan etkilenmez.
 */
final class PkAiProvider
{
    private const SETTING = 'ai';

    // Ayarlar sayfası: ad, .env anahtar adı, önerilen modeller (rapor başına maliyet ~20 bin girdi + 5 bin çıktı token).
    public const PROVIDERS = [
        'gemini' => [
            'label' => 'Google Gemini',
            'note' => 'Şu ana kadarki raporlar bununla okundu. Yoğunlukta sık 503 veriyor.',
            'key_env' => 'GEMINI_API_KEY',
            'models' => [
                ['id' => 'gemini-3.6-flash', 'note' => '~0,034 $/rapor (Ocak 2027\'den itibaren 2 katı)'],
                ['id' => 'gemini-3.7-flash', 'note' => '~0,034 $/rapor'],
                ['id' => 'gemini-3.8-flash', 'note' => '~0,034 $/rapor'],
                ['id' => 'gemini-3.5-flash-lite', 'note' => '~0,019 $/rapor, daha hafif'],
            ],
        ],
        'openai' => [
            'label' => 'OpenAI (ChatGPT)',
            'note' => 'Ücretli API anahtarı gerekir; ChatGPT aboneliği API\'yi kapsamaz.',
            'key_env' => 'OPENAI_API_KEY',
            'models' => [
                ['id' => 'gpt-6-luna', 'note' => '~0,005 $/rapor, en ekonomik'],
                ['id' => 'gpt-5.4-mini', 'note' => '~0,038 $/rapor'],
                ['id' => 'gpt-6-sol', 'note' => '~0,09 $/rapor, daha güçlü'],
            ],
        ],
        'nvidia' => [
            'label' => 'NVIDIA NIM',
            'note' => 'build.nvidia.com ücretsiz anahtarı; istek sınırı ve hız değişken.',
            'key_env' => 'NVIDIA_NIM_API_KEY',
            'models' => [
                ['id' => 'openai/gpt-oss-20b', 'note' => 'ücretsiz'],
                ['id' => 'openai/gpt-oss-120b', 'note' => 'ücretsiz, daha güçlü'],
            ],
        ],
        'local' => [
            'label' => 'Yerel sunucu',
            'note' => 'Kurumun kendi makinesindeki model (llama-server, vLLM, Ollama); veri dışarı çıkmaz. Çok ajanlı okumayla kullanılır.',
            'key_env' => 'PKTAKIP_LOCAL_API_KEY',
            // llama-server / Ollama anahtar istemez.
            'key_optional' => true,
            'models' => [
                ['id' => 'nuextract3', 'note' => 'llama-server, laptop 4 GB kart (masaüstündeki başlat dosyası)'],
                ['id' => 'openai/gpt-oss-20b', 'note' => 'vLLM adı, 16 GB kart'],
                ['id' => 'gpt-oss:20b', 'note' => 'Ollama adı'],
            ],
        ],
    ];

    private static ?array $settings = null;

    public static function name(): string
    {
        $name = (string) (self::settings()['provider'] ?? config('pktakip.ai_provider'));

        return array_key_exists($name, self::PROVIDERS) ? $name : 'gemini';
    }

    // Okuma yöntemi: true = çok ajanlı (Docling + küçük ajanlar), false = tek uzun talimat. Ayar yoksa .env (PKTAKIP_SHORT_PROMPTS).
    public static function agents(): bool
    {
        return (bool) (self::settings()['agents'] ?? config('pktakip.short_prompts'));
    }

    // Yerel sunucu adresi: sayfadan girilen, yoksa .env (PKTAKIP_LOCAL_BASE_URL).
    public static function localUrl(): ?string
    {
        return (self::settings()['local_url'] ?? null) ?: config('pktakip.local.base_url');
    }

    public static function model(?string $provider = null): ?string
    {
        $provider ??= self::name();

        return (self::settings()['models'][$provider] ?? null) ?: self::defaultModel($provider);
    }

    // Ayar yoksa kullanılan model (.env / config).
    public static function defaultModel(string $provider): ?string
    {
        return match ($provider) {
            'openai' => config('pktakip.openai.model'),
            'nvidia' => config('pktakip.nvidia.model') ?: config('services.nvidia_nim.text_model'),
            'local' => config('pktakip.local.model'),
            default => config('services.gemini.text_model'),
        };
    }

    public static function apiKey(string $provider): ?string
    {
        return self::storedKey($provider) ?? (self::envKey($provider) ?: null);
    }

    // db: Ayarlar sayfasından girilen; env: .env'deki; null: tanımlı değil.
    public static function keySource(string $provider): ?string
    {
        return self::storedKey($provider) !== null ? 'db' : (filled(self::envKey($provider)) ? 'env' : null);
    }

    // Ekranda tam anahtar gösterilmez: yalnızca son 4 karakter.
    public static function keyHint(string $provider): ?string
    {
        $key = self::apiKey($provider);

        return $key ? '…' . mb_substr($key, -4) : null;
    }

    public static function configured(string $provider): bool
    {
        return filled(self::apiKey($provider));
    }

    /**
     * $models: [sağlayıcı => model|null] (null: .env'deki model); $keys: [sağlayıcı => yeni anahtar] (girilenler);
     * $clearKeys: ayardaki anahtarı silinecek sağlayıcılar (.env'dekine döner); $options: agents (okuma yöntemi), local_url
     * (null: .env'deki).
     */
    public static function save(string $provider, array $models, array $keys, array $clearKeys, User $user, array $options = []): void
    {
        $current = self::settings();
        $stored = (array) ($current['keys'] ?? []);
        foreach ($clearKeys as $name) {
            unset($stored[$name]);
        }
        foreach ($keys as $name => $key) {
            if (filled($key)) {
                $stored[$name] = Crypt::encryptString(trim($key));
            }
        }

        PkSetting::query()->updateOrCreate(['key' => self::SETTING], [
            'value' => ['provider' => $provider, 'models' => array_filter($models), 'keys' => $stored] + array_filter($options, fn ($value) => $value !== null),
            'updated_by' => $user->id,
        ]);
        self::flush();
    }

    // Seçili (ya da verilen) sağlayıcının rapor okuma istemcisi.
    public static function client(?string $provider = null): object
    {
        return app(match ($provider ?? self::name()) {
            'openai' => PkOpenAiReportClient::class,
            'nvidia' => PkNvidiaReportClient::class,
            'local' => PkLocalReportClient::class,
            default => PkGeminiReportClient::class,
        });
    }

    public static function flush(): void
    {
        self::$settings = null;
    }

    private static function storedKey(string $provider): ?string
    {
        $encrypted = self::settings()['keys'][$provider] ?? null;
        if (!$encrypted) {
            return null;
        }
        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            // APP_KEY değiştiyse çözülemez: tanımlı değil sayılır (Ayarlar'dan yeniden girilir).
            return null;
        }
    }

    public static function envKey(string $provider): ?string
    {
        return match ($provider) {
            'openai' => config('pktakip.openai.api_key'),
            'nvidia' => config('services.nvidia_nim.api_key'),
            'local' => config('pktakip.local.api_key'),
            default => config('services.gemini.api_key'),
        };
    }

    private static function settings(): array
    {
        if (self::$settings === null) {
            try {
                self::$settings = (array) (PkSetting::query()->where('key', self::SETTING)->value('value') ?? []);
            } catch (\Throwable) {
                // Tablo yoksa (migration öncesi) .env ayarları kullanılır.
                self::$settings = [];
            }
        }

        return self::$settings;
    }
}
