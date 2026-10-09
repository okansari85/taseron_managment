<?php

namespace App\Services;

use App\Models\PkReportAnalysis;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * pktakip paket ve kredi (2026-10-03). Birim "kredi"; yalnızca başarılı rapor okuma kredi düşer: metin / OCR'lı taranmış PDF 1,
 * görüntü PDF 3, tesisat raporu ve tüp kontrol formu 5. Elle doldurma ve okunamayan rapor düşmez. Ekipman kredi düşmez; paketin
 * ayrı aktif ekipman limiti vardır (pasif ekipman sayılmaz). Paket aylık kredi yükler; kredi yıl içinde birikir, yılda bir
 * sıfırlanır (yüklemeler zamanlayıcı olmadan, hesaba ilk bakışta geriye dönük yapılır).
 *
 * Abonelik türleri: unlimited (mevcut hesaplar, paket atanana kadar geçiş), package, promo (ücretsiz deneme: denemek için üye olan herkese 20 kredi +
 * 20 ekipman), none (paketsiz). pktakip dışındaki hesaplar (Taşeron firmaları) hiç sınırlanmaz.
 */
class PkBillingService
{
    public const ACCOUNT_TYPES = ['expert', 'osgb', 'corporate'];
    public const PROMO_CREDITS = 20;
    public const PROMO_EQUIPMENT = 20;

    // Okumanın kredisi (Analiz geçmişi satırından).
    public static function costOf(string $kind, ?string $input): int
    {
        if (in_array($kind, ['installation', 'bulk'], true)) {
            return 5;
        }

        return $input === 'image' ? 3 : 1;
    }

    // Okumadan önce gereken en az kredi (okuma türü henüz belli değil: metin varsayılır).
    public static function minimumFor(string $kind): int
    {
        return $kind === 'installation' ? 5 : 1;
    }

    /** Hesabın aboneliği; yoksa açılır (yeni üye: ücretsiz deneme). Vadesi gelen yüklemeler yapılır. */
    public function subscription(int $tenantId): ?object
    {
        $type = DB::table('tenants')->where('id', $tenantId)->value('tenant_type');
        if (!in_array($type, self::ACCOUNT_TYPES, true)) {
            return null;
        }
        $row = DB::table('pk_subscriptions')->where('tenant_id', $tenantId)->first();
        if (!$row) {
            $row = $this->open($tenantId);
        }

        return $this->catchUp($row);
    }

    public function balance(int $tenantId): int
    {
        return (int) DB::table('pk_credit_entries')->where('tenant_id', $tenantId)->sum('amount');
    }

    public function activeEquipment(int $tenantId): int
    {
        return DB::table('pk_equipment')->where('tenant_id', $tenantId)->where('is_active', true)->count();
    }

    /** Panelde "Paketim" ve süper admin listesi için özet. */
    public function status(int $tenantId): array
    {
        $row = $this->subscription($tenantId);
        if (!$row) {
            return ['mode' => 'unlimited', 'unlimited' => true];
        }
        $package = $row->pk_package_id ? DB::table('pk_packages')->where('id', $row->pk_package_id)->first(['id', 'name']) : null;

        return [
            'mode' => $row->mode,
            'unlimited' => $row->mode === 'unlimited',
            'package' => $package ? ['id' => $package->id, 'name' => $package->name] : null,
            'monthly_credits' => (int) $row->monthly_credits,
            'credits' => $this->balance($tenantId),
            'equipment_limit' => $row->mode === 'unlimited' ? null : ($row->equipment_limit === null ? null : (int) $row->equipment_limit),
            'active_equipment' => $this->activeEquipment($tenantId),
            'started_at' => $row->started_at,
            'next_grant_at' => $row->next_grant_at,
            'resets_at' => $row->resets_at,
            'note' => $row->note,
        ];
    }

    /** Okuma yapılabilir mi (yeterli kredi); değilse anlaşılır mesajla durdurur. */
    public function assertCanRead(int $tenantId, string $kind): void
    {
        $row = $this->subscription($tenantId);
        if (!$row || $row->mode === 'unlimited') {
            return;
        }
        $need = self::minimumFor($kind);
        $balance = $this->balance($tenantId);
        if ($balance < $need) {
            abort(response()->json([
                'message' => $balance > 0
                    ? "Bu rapor için {$need} kredi gerekir, kalan krediniz {$balance}. Paketinizi yükseltin ya da ek kredi alın. Raporu elle doldurabilirsiniz."
                    : 'Krediniz bitti. Rapor okutmak için paketinizi yükseltin ya da ek kredi alın. Raporu elle doldurabilirsiniz.',
                'code' => 'pk_credits',
            ], 402));
        }
    }

    /** $count yeni aktif ekipman eklenebilir mi (paketin aktif ekipman limiti). */
    public function assertCanAddEquipment(int $tenantId, int $count = 1): void
    {
        $row = $this->subscription($tenantId);
        if (!$row || $row->mode === 'unlimited' || $row->equipment_limit === null || $count < 1) {
            return;
        }
        $limit = (int) $row->equipment_limit;
        $active = $this->activeEquipment($tenantId);
        if ($active + $count > $limit) {
            $left = max(0, $limit - $active);
            abort(response()->json([
                'message' => $left > 0
                    ? "Paketinizde {$left} ekipmanlık yer kaldı ({$active} / {$limit}); {$count} ekipman eklenemez. Paketinizi yükseltin ya da kullanılmayan ekipmanları pasife alın."
                    : "Ekipman limitiniz doldu ({$active} / {$limit}). Paketinizi yükseltin ya da kullanılmayan ekipmanları pasife alın.",
                'code' => 'pk_equipment_limit',
            ], 402));
        }
    }

    /** Başarılı okumanın kredisi düşülür (Analiz geçmişi satırı açılınca; aynı satır iki kez düşülmez). */
    public function chargeReading(PkReportAnalysis $analysis): void
    {
        if ($analysis->status !== 'read' || !$analysis->tenant_id || !$this->subscription((int) $analysis->tenant_id)) {
            return;
        }
        $cost = self::costOf((string) $analysis->kind, $analysis->input);
        DB::table('pk_credit_entries')->insertOrIgnore([
            'tenant_id' => $analysis->tenant_id, 'amount' => -$cost, 'reason' => 'reading', 'pk_report_analysis_id' => $analysis->id,
            'user_id' => $analysis->user_id, 'note' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // --- Süper admin ---

    /** Hesaba paket ata (bugünden başlar: ilk ayın kredisi hemen yüklenir) ya da sınırsız / paketsiz yap. */
    public function assign(int $tenantId, string $mode, ?int $packageId, ?int $userId, ?string $note = null): void
    {
        if (!$this->subscription($tenantId)) {
            throw ValidationException::withMessages(['tenant' => 'Bu hesap pktakip hesabı değil.']);
        }
        $today = CarbonImmutable::today();
        $values = ['mode' => $mode, 'pk_package_id' => null, 'monthly_credits' => 0, 'equipment_limit' => null, 'started_at' => $today->toDateString(),
            'next_grant_at' => null, 'resets_at' => null, 'note' => $note, 'updated_at' => now()];
        if ($mode === 'package') {
            $package = DB::table('pk_packages')->where('id', $packageId)->first();
            $accountType = DB::table('tenants')->where('id', $tenantId)->value('tenant_type');
            if (!$package || $package->account_type !== $accountType) {
                throw ValidationException::withMessages(['pk_package_id' => 'Paket bu hesap türü için değil.']);
            }
            $values = ['pk_package_id' => $package->id, 'monthly_credits' => $package->monthly_credits, 'equipment_limit' => $package->equipment_limit,
                'next_grant_at' => $today->toDateString(), 'resets_at' => $today->addYear()->toDateString()] + $values;
        } elseif ($mode === 'none') {
            $values['equipment_limit'] = 0;
        }
        DB::transaction(function () use ($tenantId, $values, $userId) {
            DB::table('pk_subscriptions')->where('tenant_id', $tenantId)->update($values);
            if ($values['mode'] === 'package') {
                // Yeni pakete geçişte eski bakiye sıfırlanır, yeni paketin ilk ayı yüklenir.
                $this->resetBalance($tenantId, $userId, 'Paket değişti: önceki kredi sıfırlandı');
            }
        });
        $this->catchUp(DB::table('pk_subscriptions')->where('tenant_id', $tenantId)->first());
    }

    public function addManual(int $tenantId, int $amount, ?int $userId, ?string $note): void
    {
        if (!$this->subscription($tenantId)) {
            throw ValidationException::withMessages(['tenant' => 'Bu hesap pktakip hesabı değil.']);
        }
        DB::table('pk_credit_entries')->insert([
            'tenant_id' => $tenantId, 'amount' => $amount, 'reason' => 'manual', 'user_id' => $userId, 'note' => $note ?: null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Kredi hareketleri (en yeni önce, sayfalı); okuma satırlarında rapor türü ve dosya adı. */
    public function entries(int $tenantId, int $page = 1, ?int $userId = null): array
    {
        $this->subscription($tenantId);
        $query = DB::table('pk_credit_entries as e')
            ->leftJoin('pk_report_analyses as a', 'a.id', '=', 'e.pk_report_analysis_id')
            ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->where('e.tenant_id', $tenantId)
            ->when($userId, fn ($q) => $q->where('e.user_id', $userId))
            ->orderByDesc('e.created_at')->orderByDesc('e.id');
        $result = $query->paginate(50, ['e.id', 'e.amount', 'e.reason', 'e.note', 'e.created_at', 'u.name as user', 'a.uuid as analysis', 'a.kind', 'a.input', 'a.file_name', 'a.detected_type'], 'page', $page);

        return ['data' => $result->items(), 'total' => $result->total(), 'current_page' => $result->currentPage(), 'last_page' => $result->lastPage()];
    }

    // --- İç işler ---

    private function open(int $tenantId): object
    {
        return DB::transaction(function () use ($tenantId) {
            // Ücretsiz deneme her yeni üyeye (kullanıcı, 2026-10-05: "denemek için üye olanlar"); sınır yok.
            $promo = true;
            // Aynı anda iki istek açarsa ikincisi eklemez (tenant_id benzersiz); promosyon kredisi bir kez yüklenir.
            $created = DB::table('pk_subscriptions')->insertOrIgnore([
                'tenant_id' => $tenantId, 'mode' => $promo ? 'promo' : 'none', 'monthly_credits' => 0,
                'equipment_limit' => $promo ? self::PROMO_EQUIPMENT : 0, 'started_at' => now()->toDateString(),
                'note' => $promo ? 'Ücretsiz deneme' : null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($created && $promo) {
                DB::table('pk_credit_entries')->insert([
                    'tenant_id' => $tenantId, 'amount' => self::PROMO_CREDITS, 'reason' => 'promo', 'note' => 'Ücretsiz deneme kredisi',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return DB::table('pk_subscriptions')->where('tenant_id', $tenantId)->first();
        });
    }

    // Vadesi gelen aylık yüklemeler ve yıllık sıfırlama (geriye dönük, sırayla).
    private function catchUp(object $row): object
    {
        if ($row->mode !== 'package' || !$row->next_grant_at) {
            return $row;
        }
        $today = CarbonImmutable::today();
        $next = CarbonImmutable::parse($row->next_grant_at);
        if ($next->greaterThan($today)) {
            return $row;
        }

        return DB::transaction(function () use ($row, $today) {
            $row = DB::table('pk_subscriptions')->where('id', $row->id)->lockForUpdate()->first();
            $next = CarbonImmutable::parse($row->next_grant_at);
            $resets = $row->resets_at ? CarbonImmutable::parse($row->resets_at) : null;
            for ($guard = 0; $next->lessThanOrEqualTo($today) && $guard < 120; $guard++) {
                if ($resets && $resets->lessThanOrEqualTo($next)) {
                    $this->resetBalance($row->tenant_id, null, 'Yıllık sıfırlama', $resets);
                    $resets = $resets->addYear();
                }
                DB::table('pk_credit_entries')->insert([
                    'tenant_id' => $row->tenant_id, 'amount' => (int) $row->monthly_credits, 'reason' => 'grant',
                    'note' => 'Aylık paket kredisi', 'created_at' => $next->startOfDay(), 'updated_at' => now(),
                ]);
                $next = $next->addMonthNoOverflow();
            }
            DB::table('pk_subscriptions')->where('id', $row->id)->update(['next_grant_at' => $next->toDateString(), 'resets_at' => $resets?->toDateString(), 'updated_at' => now()]);

            return DB::table('pk_subscriptions')->where('id', $row->id)->first();
        });
    }

    private function resetBalance(int $tenantId, ?int $userId, string $note, ?CarbonImmutable $at = null): void
    {
        $balance = $this->balance($tenantId);
        if ($balance === 0) {
            return;
        }
        DB::table('pk_credit_entries')->insert([
            'tenant_id' => $tenantId, 'amount' => -$balance, 'reason' => 'reset', 'user_id' => $userId, 'note' => $note,
            'created_at' => ($at ?? CarbonImmutable::now())->startOfDay(), 'updated_at' => now(),
        ]);
    }
}
