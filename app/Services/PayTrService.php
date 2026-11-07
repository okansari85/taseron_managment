<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * PayTR iFrame API (2026-11-07): ödeme jetonu (get-token) ve bildirim (callback) imzasının doğrulanması. Anahtarlar
 * config/services.php → paytr; yoksa ödeme kapalıdır. Otomatik yeniden deneme yok: bir istek = bir jeton.
 */
class PayTrService
{
    private const TOKEN_URL = 'https://www.paytr.com/odeme/api/get-token';

    public function configured(): bool
    {
        $c = config('services.paytr');

        return filled($c['merchant_id'] ?? null) && filled($c['merchant_key'] ?? null) && filled($c['merchant_salt'] ?? null);
    }

    /**
     * Sipariş için iFrame jetonu. $order: merchant_oid, email, user_ip, total_kurus, basket_name, total (TL, "298.80"),
     * name, address, phone. PayTR reddederse RuntimeException (mesajı PayTR'nin gerekçesi).
     */
    public function token(array $order): string
    {
        $c = config('services.paytr');
        $testMode = $c['test_mode'] ? '1' : '0';
        $basket = base64_encode(json_encode([[$order['basket_name'], $order['total'], 1]]));
        $noInstallment = '1';
        $maxInstallment = '0';
        $currency = 'TL';

        $hashStr = $c['merchant_id'] . $order['user_ip'] . $order['merchant_oid'] . $order['email'] . $order['total_kurus']
            . $basket . $noInstallment . $maxInstallment . $currency . $testMode;
        $landing = rtrim($c['landing_url'], '/');

        $response = Http::asForm()->timeout(20)->post(self::TOKEN_URL, [
            'merchant_id' => $c['merchant_id'],
            'user_ip' => $order['user_ip'],
            'merchant_oid' => $order['merchant_oid'],
            'email' => $order['email'],
            'payment_amount' => $order['total_kurus'],
            'paytr_token' => $this->sign($hashStr . $c['merchant_salt']),
            'user_basket' => $basket,
            'debug_on' => $testMode,
            'no_installment' => $noInstallment,
            'max_installment' => $maxInstallment,
            // PayTR alan sınırları: ad 60, adres 400, telefon 20 karakter.
            'user_name' => mb_substr($order['name'], 0, 60),
            'user_address' => mb_substr($order['address'], 0, 400),
            'user_phone' => mb_substr($order['phone'], 0, 20),
            // Uygulama içi siparişte dönüş adresleri $order'dan gelir; site siparişi tanıtım sitesine döner.
            'merchant_ok_url' => $order['ok_url'] ?? $landing . '/odeme-basarili',
            'merchant_fail_url' => $order['fail_url'] ?? $landing . '/odeme-basarisiz',
            'timeout_limit' => 30,
            'currency' => $currency,
            'test_mode' => $testMode,
            'lang' => 'tr',
        ]);

        if ($response->json('status') !== 'success' || !filled($response->json('token'))) {
            throw new RuntimeException((string) ($response->json('reason') ?? ('HTTP ' . $response->status())));
        }

        return $response->json('token');
    }

    /**
     * Paket fiyatından (KDV hariç, TL) tutarlar. Para kuruş tamsayısıyla: KDV %20, kuruşa yuvarlanır (yarım yukarı).
     * Döner: total_kurus (PayTR payment_amount) ve TL metinleri net / vat / total ("298.80").
     */
    public function amounts(float|string $price): array
    {
        $net = (int) round((float) $price * 100);
        $vat = intdiv($net * 20 + 50, 100);
        $total = $net + $vat;
        $tl = fn (int $kurus) => sprintf('%d.%02d', intdiv($kurus, 100), $kurus % 100);

        return ['total_kurus' => $total, 'net' => $tl($net), 'vat' => $tl($vat), 'total' => $tl($total)];
    }

    // Bildirim gerçekten PayTR'den mi: hash = base64(hmac_sha256(merchant_oid . salt . status . total_amount, key)).
    public function validCallback(array $post): bool
    {
        if (!$this->configured()) {
            return false;
        }
        $c = config('services.paytr');
        $expected = $this->sign(($post['merchant_oid'] ?? '') . $c['merchant_salt'] . ($post['status'] ?? '') . ($post['total_amount'] ?? ''));

        return is_string($post['hash'] ?? null) && hash_equals($expected, $post['hash']);
    }

    private function sign(string $value): string
    {
        return base64_encode(hash_hmac('sha256', $value, config('services.paytr.merchant_key'), true));
    }
}
