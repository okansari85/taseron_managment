<?php

namespace Tests\Feature;

use App\Mail\PkOrderConfirmationMail;
use App\Models\User;
use App\Services\PkBillingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * PayTR iFrame ödemesi (PkPayTrController). Bellekteki sqlite (phpunit.xml), yalnız bu akışın tabloları kurulur: tüm
 * migration'lar sqlite'ta çalışmıyor (MySQL information_schema). PayTR'ye istek gitmez (Http::fake), sahte anahtarlar.
 */
class PkPayTrTest extends TestCase
{
    private const MIGRATIONS = [
        '0001_01_01_000000_create_users_table.php',
        '2026_08_17_143227_create_tenants_table.php',
        '2026_08_17_212633_create_permission_tables.php',
        '2026_08_21_000000_add_logo_path_to_tenants_table.php',
        '2026_09_03_000001_create_user_scopes_table.php',
        '2026_09_04_000002_add_contractor_id_to_users_table.php',
        '2026_09_04_000003_add_is_expert_to_users_table.php',
        '2026_09_05_000002_add_featured_brand_id_to_tenants_table.php',
        '2026_09_07_000005_add_operational_area_enabled_to_tenants_table.php',
        '2026_09_07_000008_add_location_view_mode_to_tenants_table.php',
        '2026_09_22_000001_add_tenant_type_to_tenants_table.php',
        '2026_09_22_140000_create_expert_invitations_table.php',
        '2026_09_29_100000_create_pk_account_users_table.php',
        '2026_10_03_130000_create_pk_billing_tables.php',
        '2026_10_10_090000_add_workplace_limit_to_pk_billing.php',
        '2026_11_07_100000_create_pk_payment_orders_table.php',
        '2026_11_07_110000_add_paid_until_and_agreements_version.php',
    ];

    private int $packageId;

    // Sahte PayTR get-token yanıtı (test değiştirebilir).
    private array $paytrResponse = ['status' => 'success', 'token' => 'iframe-token-1'];

    protected function setUp(): void
    {
        parent::setUp();
        // Bu tablolar başka tablolara (marka, taşeron, rapor) bağlı; onlar kurulmadığı için yabancı anahtar denetimi kapalı.
        config(['database.connections.sqlite.foreign_key_constraints' => false]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--path' => array_map(fn ($f) => 'database/migrations/' . $f, self::MIGRATIONS)]);
        Role::findOrCreate('tenant', 'web');

        config([
            'services.paytr.merchant_id' => '123456', 'services.paytr.merchant_key' => 'TESTKEY', 'services.paytr.merchant_salt' => 'TESTSALT',
            'services.paytr.test_mode' => true, 'services.paytr.landing_url' => 'https://pktakip.test',
        ]);
        Http::fake(['www.paytr.com/*' => fn () => Http::response($this->paytrResponse)]);
        Mail::fake();

        $this->packageId = DB::table('pk_packages')->insertGetId([
            'name' => 'PayTR Test', 'account_type' => 'expert', 'monthly_credits' => 50, 'workplace_limit' => 25,
            'monthly_price' => 249, 'yearly_price' => null, 'is_active' => true, 'sort_order' => 99, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function form(array $override = []): array
    {
        return $override + [
            'type' => 'expert', 'name' => 'Deneme Uzman', 'email' => 'paytr-deneme@example.test', 'phone' => '05320000000',
            'address' => 'Deneme Mah. 1. Sok. No:1 Kadıköy İstanbul', 'plan' => 'PayTR Test', 'period' => 'monthly',
            'agreements' => true, 'kvkk' => true,
        ];
    }

    private function notify(string $oid, string $status, string $total = '29880', array $extra = []): \Illuminate\Testing\TestResponse
    {
        $hash = base64_encode(hash_hmac('sha256', $oid . 'TESTSALT' . $status . $total, 'TESTKEY', true));

        return $this->post('/api/paytr/callback', $extra + [
            'merchant_oid' => $oid, 'status' => $status, 'total_amount' => $total, 'hash' => $hash,
            'test_mode' => '1', 'payment_type' => 'card', 'currency' => 'TL', 'payment_amount' => $total,
        ]);
    }

    public function test_start_tutari_sunucuda_hesaplar_ve_jeton_doner(): void
    {
        $response = $this->postJson('/api/pk-signup/paytr/start', $this->form());

        $response->assertCreated()
            ->assertJsonPath('token', 'iframe-token-1')
            ->assertJsonPath('amounts', ['net' => '249.00', 'vat' => '49.80', 'total' => '298.80']);
        $oid = $response->json('merchant_oid');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{1,64}$/', $oid);
        $this->assertDatabaseHas('pk_payment_orders', ['merchant_oid' => $oid, 'status' => 'pending', 'total_amount' => '298.80']);

        Http::assertSent(function ($request) use ($oid) {
            $basket = base64_encode(json_encode([['PayTR Test (aylık), KDV dahil', '298.80', 1]]));
            $hashStr = '123456' . '127.0.0.1' . $oid . 'paytr-deneme@example.test' . '29880' . $basket . '1' . '0' . 'TL' . '1';
            $token = base64_encode(hash_hmac('sha256', $hashStr . 'TESTSALT', 'TESTKEY', true));

            return $request['payment_amount'] == 29880 && $request['user_basket'] === $basket && $request['paytr_token'] === $token
                && $request['merchant_ok_url'] === 'https://pktakip.test/odeme-basarili' && $request['debug_on'] === '1';
        });
    }

    public function test_kayitli_e_posta_ve_eksik_alanlar_422(): void
    {
        User::query()->create(['name' => 'Var', 'email' => 'paytr-deneme@example.test', 'password' => 'x']);

        $this->postJson('/api/pk-signup/paytr/start', $this->form())->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/api/pk-signup/paytr/start', $this->form(['email' => 'baska@example.test', 'agreements' => false, 'phone' => '']))
            ->assertStatus(422)->assertJsonValidationErrors(['agreements', 'phone']);
        // Yıllık fiyatı tanımsız paket.
        $this->postJson('/api/pk-signup/paytr/start', $this->form(['email' => 'baska@example.test', 'period' => 'yearly']))
            ->assertStatus(422)->assertJsonValidationErrors('period');
        $this->assertSame(0, DB::table('pk_payment_orders')->count());
        Http::assertNothingSent();
    }

    public function test_anahtar_yoksa_503(): void
    {
        config(['services.paytr.merchant_key' => null]);

        $this->postJson('/api/pk-signup/paytr/start', $this->form())->assertStatus(503);
        $this->notify('PKYOK', 'success')->assertStatus(400);
    }

    public function test_callback_yanlis_hash_400_dogru_hash_hesap_acar_tekrar_bildirim_ikinci_hesap_acmaz(): void
    {
        $oid = $this->postJson('/api/pk-signup/paytr/start', $this->form())->json('merchant_oid');

        $this->post('/api/paytr/callback', ['merchant_oid' => $oid, 'status' => 'success', 'total_amount' => '29880', 'hash' => 'sahte'])
            ->assertStatus(400);
        $this->assertDatabaseHas('pk_payment_orders', ['merchant_oid' => $oid, 'status' => 'pending']);

        $ok = $this->notify($oid, 'success');
        $ok->assertOk();
        $this->assertSame('OK', $ok->getContent());
        $this->assertStringStartsWith('text/plain', $ok->headers->get('Content-Type'));

        $order = DB::table('pk_payment_orders')->where('merchant_oid', $oid)->first();
        $this->assertSame('paid', $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertNotNull($order->tenant_id);
        $this->assertSame('expert', DB::table('tenants')->where('id', $order->tenant_id)->value('tenant_type'));
        $sub = DB::table('pk_subscriptions')->where('tenant_id', $order->tenant_id)->first();
        $this->assertSame('package', $sub->mode);
        $this->assertSame($this->packageId, (int) $sub->pk_package_id);
        $this->assertStringContainsString("PayTR {$oid}", $sub->note);
        $this->assertSame(50, (int) DB::table('pk_credit_entries')->where('tenant_id', $order->tenant_id)->sum('amount'));
        $this->assertArrayNotHasKey('hash', json_decode($order->paytr_payload, true));
        $this->assertSame('2026-11-07', $order->agreements_version);
        $this->assertSame(now()->toImmutable()->startOfDay()->addMonthNoOverflow()->toDateString(), $sub->paid_until);

        // Sipariş onay e-postası: tek, alıcıya; satıcı, tutar ve sözleşme bilgisi içinde.
        Mail::assertSent(PkOrderConfirmationMail::class, 1);
        Mail::assertSent(PkOrderConfirmationMail::class, function (PkOrderConfirmationMail $mail) use ($oid) {
            $html = $mail->render();

            return $mail->hasTo('paytr-deneme@example.test') && $mail->envelope()->subject === "PKTakip siparişiniz onaylandı — {$oid}"
                && str_contains($html, '3861091773') && str_contains($html, '0386109177300001') && str_contains($html, '249,00 TL')
                && str_contains($html, '49,80 TL') && str_contains($html, '298,80 TL') && str_contains($html, 'kendiliğinden yenilenmez')
                && str_contains($html, 'https://pktakip.com/mesafeli-satis-sozlesmesi') && str_contains($html, 'metin sürümü 2026-11-07');
        });

        // Aynı bildirim ikinci kez: yeni hesap ve ikinci e-posta yok, sipariş değişmez.
        $this->notify($oid, 'success')->assertOk();
        $this->assertSame(1, User::query()->where('email', 'paytr-deneme@example.test')->count());
        $this->assertSame(1, DB::table('tenants')->count());
        Mail::assertSent(PkOrderConfirmationMail::class, 1);
    }

    private function paidTenant(string $period = 'monthly', string $total = '29880'): int
    {
        $oid = $this->postJson('/api/pk-signup/paytr/start', $this->form(['period' => $period]))->json('merchant_oid');
        $this->notify($oid, 'success', $total)->assertOk();

        return (int) DB::table('pk_payment_orders')->where('merchant_oid', $oid)->value('tenant_id');
    }

    private function grants(int $tenantId): int
    {
        return DB::table('pk_credit_entries')->where('tenant_id', $tenantId)->where('reason', 'grant')->count();
    }

    public function test_aylik_odemede_ikinci_ay_kredisi_gelmez(): void
    {
        $tenantId = $this->paidTenant();

        Carbon::setTestNow(now()->addMonths(2));
        $row = app(PkBillingService::class)->subscription($tenantId);

        $this->assertSame(1, $this->grants($tenantId));
        // Kalan kredi ve işyeri limiti aynen durur; yalnız yeni kredi gelmez.
        $this->assertSame(50, app(PkBillingService::class)->balance($tenantId));
        $this->assertSame(25, (int) $row->workplace_limit);
        $this->assertNotNull($row->paid_until);
    }

    public function test_yillik_odemede_12_ay_kredi_13_ay_yok(): void
    {
        DB::table('pk_packages')->where('id', $this->packageId)->update(['yearly_price' => 2490]);
        // 2490 + %20 KDV = 2988,00 TL = 298800 kuruş
        $tenantId = $this->paidTenant('yearly', '298800');
        $billing = app(PkBillingService::class);
        $start = now();

        Carbon::setTestNow($start->copy()->addMonths(11)->addDay());
        $billing->subscription($tenantId);
        $this->assertSame(12, $this->grants($tenantId));

        Carbon::setTestNow($start->copy()->addMonths(14));
        $billing->subscription($tenantId);
        $this->assertSame(12, $this->grants($tenantId));
    }

    public function test_paid_until_bosken_eski_davranis_ve_elle_atama_temizler(): void
    {
        $tenantId = $this->paidTenant();
        $billing = app(PkBillingService::class);

        // Süper admin elle paket atar: dönem sonu temizlenir, süresiz (eski davranış).
        $billing->assign($tenantId, 'package', $this->packageId, null, 'Elle');
        $this->assertNull(DB::table('pk_subscriptions')->where('tenant_id', $tenantId)->value('paid_until'));
        $this->assertSame(50, $billing->balance($tenantId));

        Carbon::setTestNow(now()->addMonths(2));
        $billing->subscription($tenantId);
        $this->assertSame(150, $billing->balance($tenantId));
    }

    public function test_callback_basarisiz_odeme_failed(): void
    {
        $oid = $this->postJson('/api/pk-signup/paytr/start', $this->form())->json('merchant_oid');

        $this->notify($oid, 'failed', '29880', ['failed_reason_code' => '2', 'failed_reason_msg' => 'Yetersiz bakiye'])->assertOk();

        $order = DB::table('pk_payment_orders')->where('merchant_oid', $oid)->first();
        $this->assertSame('failed', $order->status);
        $this->assertSame('2 Yetersiz bakiye', $order->failed_reason);
        $this->assertSame(0, User::query()->where('email', 'paytr-deneme@example.test')->count());
    }

    public function test_odeme_sirasinda_e_posta_alinirsa_attention(): void
    {
        $oid = $this->postJson('/api/pk-signup/paytr/start', $this->form())->json('merchant_oid');
        User::query()->create(['name' => 'Araya giren', 'email' => 'paytr-deneme@example.test', 'password' => 'x']);

        $this->notify($oid, 'success')->assertOk();

        $order = DB::table('pk_payment_orders')->where('merchant_oid', $oid)->first();
        $this->assertSame('attention', $order->status);
        $this->assertNull($order->tenant_id);
        $this->assertSame(0, DB::table('tenants')->count());
    }

    public function test_jeton_alinamazsa_siparis_failed_502(): void
    {
        $this->paytrResponse = ['status' => 'failed', 'reason' => 'user_ip hatali'];

        $this->postJson('/api/pk-signup/paytr/start', $this->form())->assertStatus(502);
        $this->assertDatabaseHas('pk_payment_orders', ['status' => 'failed', 'failed_reason' => 'get-token: user_ip hatali']);
    }
}
