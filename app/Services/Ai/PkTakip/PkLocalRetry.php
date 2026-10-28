<?php

namespace App\Services\Ai\PkTakip;

use RuntimeException;

/**
 * Yalnız yerel model için tek tekrar: gpt-oss küçük modeli bazen işi "zor, atla" diye boş ya da bozuk JSON döndürüyor
 * (23 Eki ölçümü; aynı girdiyle tekrar sorulunca doğru cevap geliyor). Ücretli sağlayıcılarda tekrar yok (proje kuralı).
 */
class PkLocalRetry
{
    /**
     * Sağlayıcı istemcisinin tek-tekrarlı sarmalı: ->extract(...) çağrısı aynen yazılır.
     *
     * @param callable(array): bool $empty cevap açıkça boş mu
     */
    public static function client(?string $provider, callable $empty): object
    {
        return new class(PkAiProvider::client($provider), $provider, $empty) {
            public function __construct(private object $client, private ?string $provider, private $empty)
            {
            }

            public function extract(mixed ...$args): array
            {
                return PkLocalRetry::once($this->provider, fn () => $this->client->extract(...$args), $this->empty);
            }
        };
    }

    /**
     * @param callable(): array $call   alt ajanın isteği
     * @param callable(array): bool $empty cevap açıkça boş mu
     */
    public static function once(?string $provider, callable $call, callable $empty): array
    {
        if (($provider ?? PkAiProvider::name()) !== 'local') {
            return $call();
        }
        try {
            $result = $call();
            if (!$empty($result)) {
                return $result;
            }
        } catch (RuntimeException) {
            // Bozuk / yarım JSON: bir kez daha sorulur; ikincisi de bozuksa hata çağırana gider.
        }

        return $call();
    }
}
