<?php

namespace Tests\Unit;

use App\Services\Ai\PkTakip\PkLocalRetry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PkLocalRetryTest extends TestCase
{
    public function test_local_retries_once_on_empty_or_broken_answer(): void
    {
        $answers = [['items' => []], ['items' => ['x']]];
        $calls = 0;
        $result = PkLocalRetry::once('local', function () use (&$answers, &$calls) {
            $calls++;

            return array_shift($answers);
        }, fn (array $r) => empty($r['items']));
        $this->assertSame(['items' => ['x']], $result);
        $this->assertSame(2, $calls);

        $calls = 0;
        $result = PkLocalRetry::once('local', function () use (&$calls) {
            if (++$calls === 1) {
                throw new RuntimeException('Yerel sunucu: çıktı JSON değil');
            }

            return ['ok' => true];
        }, fn () => false);
        $this->assertSame(['ok' => true], $result);
    }

    public function test_paid_providers_are_called_once(): void
    {
        $calls = 0;
        PkLocalRetry::once('openai', function () use (&$calls) {
            $calls++;

            return [];
        }, fn () => true);
        $this->assertSame(1, $calls);
    }
}
