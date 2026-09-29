<?php

namespace App\Services\Ai\PkTakip;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Yapay zeka (Gemini, OpenAI ya da NVIDIA) hata yanıtını kullanıcıya anlaşılır yazar (tekrar deneme yok). İstek sınırında
 * (429) sağlayıcının belirttiği sınır ve kendi açıklaması da gösterilir.
 */
final class PkGeminiError
{
    // $alternative: mesajın sonuna eklenecek seçenek (örn. ' ya da "Elle doldur" ile devam edin').
    public static function friendly(RuntimeException $exception, string $alternative = ''): RuntimeException
    {
        $message = $exception->getMessage();
        $provider = match (true) {
            str_starts_with($message, 'OpenAI') => 'OpenAI',
            str_starts_with($message, 'NVIDIA') => 'NVIDIA',
            default => 'Gemini',
        };
        if (!preg_match('/\(HTTP (402|429|500|502|503|504)\)/', $message, $status)) {
            return $exception;
        }
        if ($status[1] === '402') {
            return new RuntimeException("{$provider} hesabında kredi ya da bakiye kalmadı{$alternative}.", 0, $exception);
        }
        if ($status[1] !== '429') {
            return new RuntimeException("{$provider} şu an yoğun ya da yanıt veremiyor, birkaç dakika sonra tekrar deneyin{$alternative}.", 0, $exception);
        }

        $body = json_decode(Str::after($message, '): '), true);
        $detail = trim((string) ($body['error']['message'] ?? ''));
        $lower = mb_strtolower($message);
        $wait = preg_match('/(?:retry|try again) in ([\d.]+)\s*s\b/i', $message, $match) ? (int) ceil((float) $match[1])
            : (preg_match('/"retryDelay"\s*:\s*"(\d+)s"/', $message, $match) ? (int) $match[1] : null);

        $text = match (true) {
            // OpenAI: hesapta bakiye / kota yok.
            Str::contains($lower, 'insufficient_quota') => 'OpenAI hesabında bakiye ya da kota yok; OpenAI faturalandırma sayfasından bakiye yükleyin',
            Str::contains($lower, ['perday', 'per day', 'per_day']) => "{$provider} günlük istek sınırı doldu; sınır Pasifik saatiyle gece yarısı (Türkiye'de sabah 10-11 arası) sıfırlanır, o zaman tekrar deneyin",
            Str::contains($lower, ['perminute', 'per minute', 'per_minute', 'rate limit']) => "{$provider} dakikalık istek sınırı doldu; " . ($wait ? "{$wait} sn" : '1 dakika') . ' sonra tekrar deneyin',
            default => "{$provider} istek sınırı doldu; daha sonra tekrar deneyin",
        };

        return new RuntimeException($text . $alternative . '.' . ($detail !== '' ? " {$provider}: " . Str::limit($detail, 400) : ''), 0, $exception);
    }
}
