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
    ];

    private static ?array $settings = null;

    public static function name(): string
    {
        $name = (string) (self::settings()['provider'] ?? config('pktakip.ai_provider'));

        return array_key_exists($name, self::PROVIDERS) ? $name : 'gemini';
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
     * $clearKeys: ayardaki anahtarı silinecek sağlayıcılar (.env'dekine döner).
     */
    public static function save(string $provider, array $models, array $keys, array $clearKeys, User $user): void
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
            'value' => ['provider' => $provider, 'models' => array_filter($models), 'keys' => $stored],
            'updated_by' => $user->id,
        ]);
        self::flush();
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
