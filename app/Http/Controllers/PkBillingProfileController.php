<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Services\PayTrService;
use App\Services\PkAccountService;
use App\Services\PkBillingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * pktakip uygulama içi paket satın alma (2026-11-07): fatura bilgileri (hesap başına), paket listesi, PayTR ödeme başlatma ve
 * hesabın ödemeleri. Site yalnız ücretsiz üyelik açar. Ödeme yapabilen: bireysel uzman hesabında kullanıcının kendisi, çok
 * kullanıcılı hesapta (OSGB / kurumsal / kamu) yalnız yönetici. Ödeme bildirimi PkPayTrController::callback'te işlenir.
 */
class PkBillingProfileController extends Controller
{
    private const PROFILE_FIELDS = ['invoice_type', 'name', 'tckn', 'tax_number', 'tax_office', 'phone', 'address', 'billing_address'];

    public function __construct(
        private TenantContext $tenantContext,
        private PkAccountService $accounts,
        private PkBillingService $billing,
        private PayTrService $paytr,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $tenant = $this->payerOrAbort($request);

        return $this->profileResponse($tenant->id);
    }

    public function update(Request $request): JsonResponse
    {
        $tenant = $this->payerOrAbort($request);
        $data = $request->validate([
            'invoice_type' => ['required', Rule::in(['bireysel', 'kurumsal'])],
            'name' => ['required', 'string', 'max:255'],
            'tckn' => ['exclude_unless:invoice_type,bireysel', 'required', 'digits:11'],
            'tax_number' => ['exclude_unless:invoice_type,kurumsal', 'required', 'digits:10'],
            'tax_office' => ['exclude_unless:invoice_type,kurumsal', 'required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:30'],
            'address' => ['required', 'string', 'max:500'],
            'billing_address' => ['required', 'string', 'max:500'],
        ], [
            'invoice_type.required' => 'Fatura türünü seçin.',
            'invoice_type.in' => 'Fatura türü bireysel ya da kurumsal olmalı.',
            'name.required' => 'Ad soyad ya da unvan zorunludur.',
            'tckn.required' => 'T.C. kimlik numarası zorunludur.',
            'tckn.digits' => 'T.C. kimlik numarası 11 rakam olmalı.',
            'tax_number.required' => 'Vergi kimlik numarası zorunludur.',
            'tax_number.digits' => 'Vergi kimlik numarası 10 rakam olmalı.',
            'tax_office.required' => 'Vergi dairesi zorunludur.',
            'phone.required' => 'Telefon zorunludur.',
            'address.required' => 'Adres zorunludur.',
            'billing_address.required' => 'Fatura adresi zorunludur.',
            '*.max' => 'Bu alan çok uzun.',
        ]);
        // Türe ait olmayan alanlar boşaltılır (bireyselden kurumsala geçince eski TCKN kalmaz).
        $data = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $data) + ['tckn' => null, 'tax_number' => null, 'tax_office' => null];
        $exists = DB::table('pk_billing_profiles')->where('tenant_id', $tenant->id)->exists();
        DB::table('pk_billing_profiles')->updateOrInsert(['tenant_id' => $tenant->id], $data + ['updated_at' => now()] + ($exists ? [] : ['created_at' => now()]));

        return $this->profileResponse($tenant->id);
    }

    /** Paketim → paket seçimi: hesabın türüne uygun etkin paketler ve ödeme yapılabilir mi. */
    public function packages(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->id();
        $status = $this->billing->status($tenantId);
        [$tenant, $reason] = $this->payer($request);
        if (!$reason && $status['unlimited']) {
            $reason = 'Hesabınız sınırsız kullanımda; ödeme gerekmez.';
        }
        if (!$reason && !$this->paytr->configured()) {
            $reason = 'Online ödeme henüz aktif değil. Lütfen bizimle iletişime geçin.';
        }
        $type = DB::table('tenants')->where('id', $tenantId)->value('tenant_type');
        $packages = in_array($type, PkBillingService::ACCOUNT_TYPES, true)
            ? DB::table('pk_packages')->where('account_type', $this->packageType($type))->where('is_active', true)
                ->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'monthly_price', 'yearly_price', 'monthly_credits', 'workplace_limit'])
                ->map(fn ($p) => [
                    'id' => (int) $p->id, 'name' => $p->name,
                    'monthly_price' => $p->monthly_price === null ? null : (float) $p->monthly_price,
                    'yearly_price' => $p->yearly_price === null ? null : (float) $p->yearly_price,
                    'monthly_credits' => (int) $p->monthly_credits,
                    'workplace_limit' => $p->workplace_limit === null ? null : (int) $p->workplace_limit,
                ])->values()
            : [];

        return response()->json(['can_pay' => $reason === null, 'cannot_pay_reason' => $reason, 'packages' => $packages, 'status' => $status]);
    }

    /** Uygulama içi ödeme: sipariş "pending" (tenant_id dolu) + PayTR jetonu. Paket, bildirim başarılı gelince uygulanır. */
    public function paytrStart(Request $request): JsonResponse
    {
        $tenant = $this->payerOrAbort($request);
        if (!$this->paytr->configured()) {
            return response()->json(['message' => 'Online ödeme henüz aktif değil. Lütfen bizimle iletişime geçin.'], 503);
        }
        $data = $request->validate([
            'pk_package_id' => ['required', 'integer'],
            'period' => ['required', Rule::in(['monthly', 'yearly'])],
            'agreements' => ['accepted'],
        ], [
            'pk_package_id.required' => 'Paket seçin.',
            'period.required' => 'Ödeme dönemini seçin.',
            'period.in' => 'Ödeme dönemi aylık ya da yıllık olmalı.',
            'agreements.accepted' => 'Mesafeli Satış Sözleşmesi ve Ön Bilgilendirme Formu onayı gereklidir.',
        ]);

        $profile = DB::table('pk_billing_profiles')->where('tenant_id', $tenant->id)->first();
        if (!$profile) {
            throw ValidationException::withMessages(['billing_profile' => 'Önce fatura bilgilerinizi kaydedin.']);
        }
        $sub = $this->billing->subscription($tenant->id);
        if ($sub->mode === 'unlimited') {
            throw ValidationException::withMessages(['pk_package_id' => 'Hesabınız sınırsız kullanımda; ödeme alınmaz.']);
        }
        $package = DB::table('pk_packages')->where('id', $data['pk_package_id'])->where('account_type', $this->packageType($tenant->tenant_type))
            ->where('is_active', true)->first();
        if (!$package) {
            throw ValidationException::withMessages(['pk_package_id' => 'Seçilen paket bulunamadı.']);
        }
        $price = $data['period'] === 'yearly' ? $package->yearly_price : $package->monthly_price;
        if ($price === null) {
            throw ValidationException::withMessages(['period' => 'Bu paket için seçilen dönemin fiyatı tanımlı değil.']);
        }
        // Ödenmiş dönem sürerken küçük pakete geçilmez (aylık fiyata göre); dönem bitince serbest.
        if ($sub->mode === 'package' && $sub->pk_package_id && $sub->paid_until && CarbonImmutable::parse($sub->paid_until)->greaterThanOrEqualTo(CarbonImmutable::today())) {
            $currentPrice = DB::table('pk_packages')->where('id', $sub->pk_package_id)->value('monthly_price');
            if ($currentPrice !== null && $package->monthly_price !== null && (float) $package->monthly_price < (float) $currentPrice) {
                $until = CarbonImmutable::parse($sub->paid_until)->format('d.m.Y');
                throw ValidationException::withMessages(['pk_package_id' => "Mevcut döneminiz {$until} tarihinde bitince küçük pakete geçebilirsiniz."]);
            }
        }

        $amounts = $this->paytr->amounts($price);
        $totalKurus = $amounts['total_kurus'];
        unset($amounts['total_kurus']);
        $user = $request->user();
        $corporate = $profile->invoice_type === 'kurumsal';
        $oid = 'PK' . now()->format('YmdHis') . Str::random(10);
        $order = [
            'merchant_oid' => $oid, 'status' => 'pending', 'type' => $tenant->tenant_type,
            'name' => $corporate ? $user->name : $profile->name, 'organization' => $corporate ? $profile->name : null,
            'email' => $user->email, 'phone' => $profile->phone, 'address' => $profile->billing_address,
            'billing' => json_encode(collect((array) $profile)->only(array_diff(self::PROFILE_FIELDS, ['phone']))->all(), JSON_UNESCAPED_UNICODE),
            'pk_package_id' => $package->id, 'period' => $data['period'],
            'net_amount' => $amounts['net'], 'vat_amount' => $amounts['vat'], 'total_amount' => $amounts['total'], 'user_ip' => (string) $request->ip(),
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'agreements_accepted_at' => now(), 'agreements_version' => config('services.paytr.agreements_version'),
            'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('pk_payment_orders')->insert($order);

        $appUrl = rtrim((string) config('services.paytr.app_url'), '/');
        $periodLabel = $data['period'] === 'yearly' ? 'yıllık' : 'aylık';
        try {
            $token = $this->paytr->token($order + [
                'total_kurus' => $totalKurus, 'total' => $amounts['total'], 'basket_name' => "{$package->name} ({$periodLabel}), KDV dahil",
                'ok_url' => $appUrl . '/expert/odeme-sonuc?durum=basarili', 'fail_url' => $appUrl . '/expert/odeme-sonuc?durum=basarisiz',
            ]);
        } catch (\Throwable $e) {
            DB::table('pk_payment_orders')->where('merchant_oid', $oid)->update([
                'status' => 'failed', 'failed_reason' => mb_substr('get-token: ' . $e->getMessage(), 0, 500), 'updated_at' => now(),
            ]);
            Log::warning('PayTR jeton alınamadı (uygulama)', ['merchant_oid' => $oid, 'tenant_id' => $tenant->id, 'reason' => $e->getMessage()]);

            return response()->json(['message' => 'Ödeme başlatılamadı. Lütfen biraz sonra tekrar deneyin ya da bizimle iletişime geçin.'], 502);
        }

        return response()->json(['token' => $token, 'merchant_oid' => $oid, 'amounts' => $amounts], 201);
    }

    /** Hesabın ödemeleri (en yeni önce, sayfalı). Site kaydıyla açılan hesabın ilk siparişi de burada (callback tenant_id yazar). */
    public function orders(Request $request): JsonResponse
    {
        $tenant = $this->payerOrAbort($request);
        $page = (int) ($request->validate(['page' => ['nullable', 'integer', 'min:1']])['page'] ?? 1);
        $result = DB::table('pk_payment_orders as o')->leftJoin('pk_packages as p', 'p.id', '=', 'o.pk_package_id')
            ->where('o.tenant_id', $tenant->id)->orderByDesc('o.created_at')->orderByDesc('o.id')
            ->paginate(20, ['o.merchant_oid', 'o.status', 'o.pk_package_id', 'p.name as package_name', 'o.period', 'o.net_amount', 'o.vat_amount',
                'o.total_amount', 'o.created_at', 'o.paid_at'], 'page', $page);
        $money = fn ($v) => sprintf('%.2f', (float) $v);

        return response()->json([
            'data' => collect($result->items())->map(fn ($o) => [
                'merchant_oid' => $o->merchant_oid, 'status' => $o->status,
                'package' => $o->pk_package_id ? ['id' => (int) $o->pk_package_id, 'name' => $o->package_name] : null,
                'period' => $o->period, 'net_amount' => $money($o->net_amount), 'vat_amount' => $money($o->vat_amount),
                'total_amount' => $money($o->total_amount), 'created_at' => $o->created_at, 'paid_at' => $o->paid_at,
            ])->values(),
            'total' => $result->total(), 'current_page' => $result->currentPage(), 'last_page' => $result->lastPage(),
        ]);
    }

    // [hesap, null] ödeme yapabilir; [hesap|null, gerekçe] yapamaz. Hesap, kullanıcının kendi hesabı ve seçili hesapla aynı olmalı.
    private function payer(Request $request): array
    {
        $user = $request->user();
        $tenant = $this->accounts->tenantOf($user);
        if (!$tenant || !$this->tenantContext->has() || $tenant->id !== $this->tenantContext->id()
            || !in_array($tenant->tenant_type, PkBillingService::ACCOUNT_TYPES, true)) {
            return [null, 'Paket satın alma ve fatura bilgileri yalnızca kendi PKTakip hesabınızda yapılır.'];
        }
        if (in_array($tenant->tenant_type, PkAccountService::MULTI_USER_TYPES, true) && $this->accounts->roleOf($tenant, $user) !== 'yonetici') {
            return [$tenant, 'Paket satın alma, ödemeler ve fatura bilgileri yalnızca hesap yöneticisine açıktır.'];
        }

        return [$tenant, null];
    }

    private function payerOrAbort(Request $request): Tenant
    {
        [$tenant, $reason] = $this->payer($request);
        abort_if($reason !== null, 403, (string) $reason);

        return $tenant;
    }

    // Kamu hesabı kurumsal paketleri kullanır (PkBillingService::assign ile aynı kural).
    private function packageType(string $tenantType): string
    {
        return $tenantType === 'public' ? 'corporate' : $tenantType;
    }

    // Kayıt yoksa gövde düz JSON null.
    private function profileResponse(int $tenantId): JsonResponse
    {
        $row = DB::table('pk_billing_profiles')->where('tenant_id', $tenantId)->first([...self::PROFILE_FIELDS, 'updated_at']);

        return $row ? response()->json($row) : response()->json()->setJson('null');
    }
}
