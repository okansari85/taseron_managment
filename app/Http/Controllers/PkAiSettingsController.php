<?php

namespace App\Http\Controllers;

use App\Models\PkSetting;
use App\Services\Ai\PkTakip\PkAiProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * pktakip Ayarlar → Yapay zeka: rapor okuyan varsayılan yapay zeka istemcisi, modeller ve API anahtarları.
 * Şimdilik tüm uzmanlara açık; ileride yalnızca süper admin. Anahtarlar tarayıcıya hiç gönderilmez (son 4 karakter).
 */
class PkAiSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json($this->present());
    }

    public function update(Request $request): JsonResponse
    {
        $providers = array_keys(PkAiProvider::PROVIDERS);
        $data = $request->validate([
            'provider' => ['required', Rule::in($providers)],
            'models' => ['nullable', 'array'],
            'models.*' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\/:-]+$/'],
            'keys' => ['nullable', 'array'],
            'keys.*' => ['nullable', 'string', 'max:500', 'regex:/^\S+$/'],
            'clear_keys' => ['nullable', 'array'],
            'clear_keys.*' => [Rule::in($providers)],
            'agents' => ['nullable', 'boolean'],
            'local_url' => ['nullable', 'url:http,https', 'max:255'],
        ], [
            'local_url.url' => 'Yerel sunucu adresi http:// ya da https:// ile başlamalı (örn. http://127.0.0.1:8090/v1).',
            'provider.in' => 'Geçersiz yapay zeka istemcisi.',
            'models.*.regex' => 'Model adında boşluk ya da geçersiz karakter var.',
            'keys.*.regex' => 'API anahtarında boşluk olamaz.',
        ]);

        $models = array_map(fn ($model) => filled($model) ? trim($model) : null, Arr::only((array) ($data['models'] ?? []), $providers));
        $keys = array_filter(Arr::only((array) ($data['keys'] ?? []), $providers), 'filled');
        $clear = array_values(array_diff((array) ($data['clear_keys'] ?? []), array_keys($keys)));

        // Seçilen istemcinin (kayıttan sonra) bir anahtarı olmalı.
        $selected = $data['provider'];
        $willHaveKey = !empty(PkAiProvider::PROVIDERS[$selected]['key_optional']) || isset($keys[$selected]) || (!in_array($selected, $clear, true) && PkAiProvider::keySource($selected) === 'db')
            || filled(PkAiProvider::envKey($selected));
        if (!$willHaveKey) {
            throw ValidationException::withMessages(['provider' => PkAiProvider::PROVIDERS[$selected]['label'] . ' için API anahtarı yok. Anahtarı girin ya da başka bir istemci seçin.']);
        }

        PkAiProvider::save($selected, $models, $keys, $clear, $request->user(), [
            'agents' => isset($data['agents']) ? (bool) $data['agents'] : null,
            'local_url' => filled($data['local_url'] ?? null) ? rtrim(trim($data['local_url']), '/') : null,
        ]);

        return response()->json($this->present());
    }

    private function present(): array
    {
        $setting = PkSetting::query()->with('updater:id,name')->where('key', 'ai')->first();

        return [
            'provider' => PkAiProvider::name(),
            'providers' => collect(PkAiProvider::PROVIDERS)->map(fn (array $meta, string $key) => [
                'key' => $key,
                'label' => $meta['label'],
                'note' => $meta['note'],
                'key_env' => $meta['key_env'],
                'key_source' => PkAiProvider::keySource($key),
                'key_hint' => PkAiProvider::keyHint($key),
                // Sayfadaki anahtar kaldırılırsa .env'deki kullanılabilir mi.
                'env_key_set' => filled(PkAiProvider::envKey($key)),
                'model' => PkAiProvider::model($key),
                'custom_model' => (bool) ($setting?->value['models'][$key] ?? null),
                'default_model' => PkAiProvider::defaultModel($key),
                'suggestions' => $meta['models'],
                'key_optional' => !empty($meta['key_optional']),
            ])->values(),
            'agents' => PkAiProvider::agents(),
            // Adres alanı: yalnız sayfadan girilmişse dolu; boşsa .env'deki geçerli.
            'local_url' => $setting?->value['local_url'] ?? null,
            'local_url_default' => config('pktakip.local.base_url'),
            // Çok ajanlı okuma tesisat raporlarında Docling ister (servis adresi ya da kurulu Python).
            'docling_ready' => filled(config('pktakip.docling_url')) || filled(config('pktakip.docling_python')),
            'updated_by' => $setting?->updater?->name,
            'updated_at' => $setting?->updated_at,
        ];
    }
}
