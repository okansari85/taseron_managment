<?php

namespace App\Services\Ai\PkTakip;

/**
 * Son yapay zeka isteğinin token kullanımı (istemciler yanıt gelince yazar, analiz geçmişi okur). İstek başına tek
 * okuma yapıldığı için son değer o okumanındır.
 */
final class PkAiUsage
{
    private static ?array $last = null;

    public static function record(mixed $inputTokens, mixed $outputTokens): void
    {
        self::$last = [
            'input_tokens' => is_numeric($inputTokens) ? (int) $inputTokens : null,
            'output_tokens' => is_numeric($outputTokens) ? (int) $outputTokens : null,
        ];
    }

    // Değeri verir ve siler (sonraki okumaya karışmasın).
    public static function take(): array
    {
        $usage = self::$last ?? ['input_tokens' => null, 'output_tokens' => null];
        self::$last = null;

        return $usage;
    }
}
