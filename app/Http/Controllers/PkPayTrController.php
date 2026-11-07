<?php

namespace App\Http\Controllers;

use App\Mail\PkOrderConfirmationMail;
use App\Models\User;
use App\Services\PayTrService;
use App\Services\PkAccountService;
use App\Services\PkBillingService;
use App\Services\SuperAdminExpertOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tanıtım sitesinden ücretli paketle üyelik, PayTR iFrame ödemesi (2026-11-07). start: sipariş "pending" yazılır, tutar
 * sunucuda hesaplanır (paket fiyatı KDV hariç + %20 KDV), PayTR jetonu döner; hesap henüz açılmaz. callback: PayTR'nin
 * sunucudan sunucuya bildirimi; ödeme başarılıysa hesap açılır (PkSignupController::checkout ile aynı yol: davet e-postası +
 * paket ataması). Para alınıp hesap açılamazsa sipariş "attention" olur, elle çözülür.
 */
class PkPayTrController extends Controller
{
    public function __construct(
        private PayTrService $paytr,
        private SuperAdminExpertOnboardingService $experts,
        private PkAccountService $accounts,
    ) {}

    public function start(Request $request): JsonResponse
    {
        // 2026-11-07: satın alma uygulama içinden (PkBillingProfileController::paytrStart); site yalnız ücretsiz üyelik.
        if (!config('services.paytr.signup_enabled')) {
            return response()->json(['message' => 'Paket satın alma uygulama içinden yapılır. Ücretsiz üye olun, giriş yaptıktan sonra Paketim ekranından satın alın.'], 410);
        }
        if (!$this->paytr->configured()) {
            return response()->json(['message' => 'Online ödeme henüz aktif değil. Lütfen bizimle iletişime geçin.'], 503);
        }

        $data = $request->validate([
            'type' => ['required', Rule::in(['expert', 'osgb', 'corporate'])],
            'name' => ['required', 'string', 'max:255'],
            'organization' => ['required_unless:type,expert', 'nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:30'],
            'address' => ['required', 'string', 'max:500'],
            'plan' => ['required', 'string', 'max:100'],
            'period' => ['required', Rule::in(['monthly', 'yearly'])],
            'agreements' => ['accepted'],
            'kvkk' => ['accepted'],
            // Görünmez tuzak alan: doluysa (bot) ödeme başlamaz.
            'website' => ['prohibited'],
        ], [
            'name.required' => 'Ad soyad zorunludur.',
            'organization.required_unless' => 'Kurum adı zorunludur.',
            'email.required' => 'E-posta zorunludur.',
            'email.email' => 'Geçerli bir e-posta adresi girin.',
            'email.unique' => 'Bu e-posta ile zaten bir hesap var. Giriş yapın ya da şifrenizi sıfırlayın.',
            'phone.required' => 'Telefon zorunludur.',
            'address.required' => 'Fatura adresi zorunludur.',
            'agreements.accepted' => 'Mesafeli Satış Sözleşmesi ve Ön Bilgilendirme Formu onayı gereklidir.',
            'kvkk.accepted' => 'KVKK Aydınlatma Metni onayı gereklidir.',
            'website.prohibited' => 'Form gönderilemedi.',
        ]);

        $package = DB::table('pk_packages')->where('name', $data['plan'])->where('account_type', $data['type'])->where('is_active', true)->first();
        if (!$package) {
            throw ValidationException::withMessages(['plan' => 'Seçilen paket bulunamadı.']);
        }
        $price = $data['period'] === 'yearly' ? $package->yearly_price : $package->monthly_price;
        if ($price === null) {
            throw ValidationException::withMessages(['period' => 'Bu paket için seçilen dönemin fiyatı tanımlı değil.']);
        }

        $amounts = $this->paytr->amounts($price);
        $total = $amounts['total_kurus'];
        unset($amounts['total_kurus']);

        $oid = 'PK' . now()->format('YmdHis') . Str::random(10);
        $periodLabel = $data['period'] === 'yearly' ? 'yıllık' : 'aylık';
        $order = [
            'merchant_oid' => $oid, 'status' => 'pending', 'type' => $data['type'], 'name' => trim($data['name']),
            'organization' => $data['type'] === 'expert' ? null : trim((string) $data['organization']), 'email' => trim($data['email']),
            'phone' => trim($data['phone']), 'address' => trim($data['address']), 'pk_package_id' => $package->id, 'period' => $data['period'],
            'net_amount' => $amounts['net'], 'vat_amount' => $amounts['vat'], 'total_amount' => $amounts['total'], 'user_ip' => (string) $request->ip(),
            'agreements_accepted_at' => now(), 'agreements_version' => config('services.paytr.agreements_version'),
            'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('pk_payment_orders')->insert($order);

        try {
            $token = $this->paytr->token($order + [
                'total_kurus' => $total, 'total' => $amounts['total'], 'basket_name' => "{$package->name} ({$periodLabel}), KDV dahil",
            ]);
        } catch (\Throwable $e) {
            DB::table('pk_payment_orders')->where('merchant_oid', $oid)->update([
                'status' => 'failed', 'failed_reason' => mb_substr('get-token: ' . $e->getMessage(), 0, 500), 'updated_at' => now(),
            ]);
            Log::warning('PayTR jeton alınamadı', ['merchant_oid' => $oid, 'reason' => $e->getMessage()]);

            return response()->json(['message' => 'Ödeme başlatılamadı. Lütfen biraz sonra tekrar deneyin ya da bizimle iletişime geçin.'], 502);
        }

        return response()->json(['token' => $token, 'merchant_oid' => $oid, 'amounts' => $amounts], 201);
    }

    /**
     * PayTR bildirimi. Yanıt düz metin "OK" olmalı; yoksa PayTR tekrar dener. Aynı bildirim tekrar ya da paralel gelse de
     * sipariş satırı kilitli olduğu için tek hesap açılır (yalnız "pending" sipariş işlenir).
     */
    public function callback(Request $request, PkBillingService $billing): Response
    {
        $post = $request->only(['merchant_oid', 'status', 'total_amount', 'hash', 'failed_reason_code', 'failed_reason_msg',
            'test_mode', 'payment_type', 'currency', 'payment_amount']);
        if (!$this->paytr->validCallback($post)) {
            Log::warning('PayTR bildirimi: hash tutmadı', ['merchant_oid' => $post['merchant_oid'] ?? null, 'ip' => $request->ip()]);

            return response('PAYTR notification failed: bad hash', 400)->header('Content-Type', 'text/plain');
        }
        $oid = (string) $post['merchant_oid'];
        $payload = json_encode(collect($post)->except('hash')->all());

        // Yalnız bu bildirimle "paid" olan siparişin numarası (ve uygulama siparişinde hizmet dönemi) döner (onay e-postası işlem kapandıktan sonra, bir kez).
        $paid = DB::transaction(function () use ($oid, $post, $payload, $billing) {
            $order = DB::table('pk_payment_orders')->where('merchant_oid', $oid)->lockForUpdate()->first();
            if (!$order) {
                Log::warning('PayTR bildirimi: sipariş yok', ['merchant_oid' => $oid]);

                return;
            }
            if ($order->status !== 'pending') {
                return;
            }

            if ($post['status'] !== 'success') {
                DB::table('pk_payment_orders')->where('id', $order->id)->update([
                    'status' => 'failed', 'paytr_payload' => $payload, 'updated_at' => now(),
                    'failed_reason' => mb_substr(trim(($post['failed_reason_code'] ?? '') . ' ' . ($post['failed_reason_msg'] ?? '')), 0, 500) ?: null,
                ]);

                return;
            }

            // Uygulama içi sipariş (tenant_id başlangıçta dolu): hesap açılmaz, ödenen dönem hesaba uygulanır.
            if ($order->tenant_id) {
                $period = $order->period === 'yearly' ? 'yıllık' : 'aylık';
                try {
                    // İç işlem: hata olursa yalnız paket değişikliği geri alınır, sipariş satırı kalır.
                    $servicePeriod = DB::transaction(fn () => $billing->applyPaidPeriod(
                        (int) $order->tenant_id, (int) $order->pk_package_id, $order->period, "Uygulamadan {$period}, PayTR {$order->merchant_oid}"
                    ));
                } catch (\Throwable $e) {
                    DB::table('pk_payment_orders')->where('id', $order->id)->update([
                        'status' => 'attention', 'paytr_payload' => $payload, 'failed_reason' => mb_substr('Paket uygulanamadı: ' . $e->getMessage(), 0, 500), 'updated_at' => now(),
                    ]);
                    Log::error('PayTR: ödeme alındı ama paket uygulanamadı', ['merchant_oid' => $oid, 'tenant_id' => $order->tenant_id, 'error' => $e->getMessage()]);

                    return;
                }
                DB::table('pk_payment_orders')->where('id', $order->id)->update([
                    'status' => 'paid', 'paid_at' => now(), 'paytr_payload' => $payload, 'updated_at' => now(),
                ]);

                return [$order->id, $servicePeriod];
            }

            // Para alındı: hesap açılamazsa sipariş "attention" kalır (veri kaybolmaz, elle çözülür).
            try {
                if (User::query()->where('email', $order->email)->exists()) {
                    throw new \RuntimeException('E-posta ödeme sırasında başka bir hesapça alındı.');
                }
                // İç işlem: hata olursa yalnız hesap açma geri alınır, sipariş satırı kalır.
                [$tenantId, $mailSent] = DB::transaction(function () use ($order, $billing) {
                    $result = $order->type === 'expert'
                        ? $this->experts->create($order->name, $order->name, $order->email)
                        : $this->accounts->create($order->type, $order->organization, $order->name, $order->email);
                    $period = $order->period === 'yearly' ? 'yıllık' : 'aylık';
                    $billing->assign($result['tenant']->id, 'package', (int) $order->pk_package_id, null, "Tanıtım sitesinden {$period}, PayTR {$order->merchant_oid}");
                    // Ödenen dönemin sonu: sonrasında yeni kredi yüklenmez (assign elle atamayı süresiz bırakır, burada yazılır).
                    $today = CarbonImmutable::today();
                    DB::table('pk_subscriptions')->where('tenant_id', $result['tenant']->id)->update([
                        'paid_until' => ($order->period === 'yearly' ? $today->addYearNoOverflow() : $today->addMonthNoOverflow())->toDateString(),
                        'updated_at' => now(),
                    ]);

                    return [$result['tenant']->id, $result['mail_sent']];
                });
            } catch (\Throwable $e) {
                DB::table('pk_payment_orders')->where('id', $order->id)->update([
                    'status' => 'attention', 'paytr_payload' => $payload, 'failed_reason' => mb_substr('Hesap açılamadı: ' . $e->getMessage(), 0, 500), 'updated_at' => now(),
                ]);
                Log::error('PayTR: ödeme alındı ama hesap açılamadı', ['merchant_oid' => $oid, 'email' => $order->email, 'error' => $e->getMessage()]);

                return;
            }

            DB::table('pk_payment_orders')->where('id', $order->id)->update([
                'status' => 'paid', 'tenant_id' => $tenantId, 'paid_at' => now(), 'paytr_payload' => $payload, 'updated_at' => now(),
            ]);
            if (!$mailSent) {
                Log::warning('PayTR: hesap açıldı ama davet e-postası gönderilemedi', ['merchant_oid' => $oid, 'tenant_id' => $tenantId]);
            }

            return [$order->id, null];
        });

        if ($paid) {
            $this->sendConfirmation(...$paid);
        }

        return response('OK', 200)->header('Content-Type', 'text/plain');
    }

    // Sipariş onayı (Mesafeli Sözleşmeler Yönetmeliği: sözleşme teyidi kalıcı veri saklayıcısıyla). Gönderilemezse sipariş
    // ve hesap etkilenmez, loglanır.
    // $servicePeriod: [başlangıç, bitiş] (uygulama siparişi; yenilemede eski dönemin sonundan başlar); yoksa abonelikten.
    private function sendConfirmation(int $orderId, ?array $servicePeriod = null): void
    {
        try {
            $order = DB::table('pk_payment_orders')->where('id', $orderId)->first();
            $subscription = DB::table('pk_subscriptions')->where('tenant_id', $order->tenant_id)->first(['started_at', 'paid_until']);
            [$startsAt, $endsAt] = $servicePeriod ?? [$subscription?->started_at, $subscription?->paid_until];
            $packageName = (string) DB::table('pk_packages')->where('id', $order->pk_package_id)->value('name');
            Mail::to($order->email)->send(new PkOrderConfirmationMail($order, $packageName, $startsAt, $endsAt));
        } catch (\Throwable $e) {
            Log::error('PayTR: sipariş onay e-postası gönderilemedi', ['order_id' => $orderId, 'error' => $e->getMessage()]);
        }
    }
}
