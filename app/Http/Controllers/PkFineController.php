<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\LocationBusinessEntity;
use App\Services\PkAccountService;
use App\Services\PkEquipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Olası idari para cezası (pktakip, Genel Bakış "Ceza" sekmesi ve Lokasyonlar kartı): seçilen işyerinin (ve lokasyon geneli
 * ekipmanların) süresi geçmiş ya da uygun olmayan aktif ekipmanları için 6331 s. Kanun 26/1-n (Md. 30 yönetmelikleri — İş
 * Ekipmanları Yönetmeliği) tutarı. Kaynak: ÇSGB "2026 yılında uygulanacak idari para cezaları" tablosu (yeniden değerleme
 * %25,49). Tutar işyerinin tehlike sınıfı ve çalışan sayısı aralığına göre; ekipman başına, aykırılık sürdükçe her ay.
 * Resmî tespit değildir; bilgilendirme amaçlıdır.
 */
class PkFineController extends Controller
{
    public const YEAR = 2026;
    public const LEGAL = '6331 s. Kanun 26/1-n — 30. madde yönetmeliklerine (İş Ekipmanları Yönetmeliği) aykırılık';

    // ÇSGB 2026 tablosu, 26/1-n satırı: çalışan aralığı → tehlike sınıfı → TL (ekipman başına, aylık).
    public const RATES = [
        'lt10' => ['Az Tehlikeli' => 22194, 'Tehlikeli' => 27742, 'Çok Tehlikeli' => 33291],
        '10_49' => ['Az Tehlikeli' => 22194, 'Tehlikeli' => 33291, 'Çok Tehlikeli' => 44388],
        '50_plus' => ['Az Tehlikeli' => 33291, 'Tehlikeli' => 44388, 'Çok Tehlikeli' => 66582],
    ];
    public const BAND_LABELS = ['lt10' => "10'dan az çalışan", '10_49' => '10–49 çalışan', '50_plus' => '50 ve üzeri çalışan'];

    public function __construct(private PkEquipmentService $equipment, private PkAccountService $accounts)
    {
    }

    // Hesabın işyerlerinin çalışan aralıkları: işyeri kimliği → aralık.
    public function bands(): JsonResponse
    {
        $bands = DB::table('pk_workplace_employee_bands as b')
            ->join('location_business_entities as l', 'l.id', '=', 'b.location_business_entity_id')
            ->whereIn('l.location_id', Location::query()->select('id'))
            ->pluck('b.band', 'b.location_business_entity_id');

        return response()->json((object) $bands->all());
    }

    // İşyerinin çalışan aralığını kaydeder (işyeri açılırken ve Ceza sekmesinde).
    public function setBand(Request $request, int $workplace): JsonResponse
    {
        $data = $request->validate(['band' => ['required', Rule::in(array_keys(self::RATES))]]);
        $tenant = $this->accounts->tenantOf($request->user());
        abort_unless($tenant, 403, 'Hesap bulunamadı.');
        abort_if(in_array($tenant->tenant_type, PkAccountService::MULTI_USER_TYPES, true) && $this->accounts->roleOf($tenant, $request->user()) !== 'yonetici', 403, 'İşyeri bilgilerini yönetici düzenler.');
        $record = LocationBusinessEntity::query()->whereKey($workplace)->whereIn('location_id', Location::query()->select('id'))->firstOrFail();

        DB::table('pk_workplace_employee_bands')->updateOrInsert(
            ['location_business_entity_id' => $record->id],
            ['band' => $data['band'], 'updated_at' => now(), 'created_at' => now()]
        );

        return response()->json(['id' => $record->id, 'band' => $data['band']]);
    }

    // Seçili işyeri için olası ceza (lokasyon geneli ekipmanlar dahil).
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate(['location_id' => ['required', 'integer'], 'workplace_id' => ['required', 'integer']]);
        $workplace = LocationBusinessEntity::query()
            ->whereKey($data['workplace_id'])
            ->where('location_id', $data['location_id'])
            ->whereIn('location_id', Location::query()->select('id'))
            ->with('businessEntity')
            ->firstOrFail();

        $band = DB::table('pk_workplace_employee_bands')->where('location_business_entity_id', $workplace->id)->value('band');
        $hazard = $workplace->hazard_class;
        $rate = $band && isset(self::RATES[$band][$hazard]) ? self::RATES[$band][$hazard] : null;

        $items = $this->equipment->list(['location_ids' => [$workplace->location_id], 'workplace_id' => $workplace->id])
            ->where('is_active', true)
            ->map(function (array $item) {
                $overdue = $item['days_left'] !== null && $item['days_left'] < 0;
                $nonconforming = $item['status'] === 'uygun_degil';

                return ($overdue || $nonconforming) ? [
                    'id' => $item['id'],
                    'name' => $item['name'] ?? ($item['type']['name'] ?? null),
                    'code' => $item['code'] ?? null,
                    'type' => $item['type']['name'] ?? null,
                    'general' => empty($item['workplace']),
                    'overdue' => $overdue,
                    'nonconforming' => $nonconforming,
                    'days_left' => $item['days_left'],
                ] : null;
            })
            ->filter()
            ->sortBy('days_left')
            ->values();

        return response()->json([
            'year' => self::YEAR,
            'legal' => self::LEGAL,
            'workplace' => ['id' => $workplace->id, 'company_name' => $workplace->businessEntity?->name, 'hazard_class' => $hazard, 'nace_code' => $workplace->nace_code, 'band' => $band, 'band_label' => $band ? self::BAND_LABELS[$band] : null],
            'bands' => collect(self::BAND_LABELS)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'rate' => $rate,
            'counts' => [
                'total' => $items->count(),
                'overdue' => $items->where('overdue', true)->count(),
                'nonconforming' => $items->where('nonconforming', true)->count(),
                'general' => $items->where('general', true)->count(),
            ],
            'total' => $rate !== null ? $rate * $items->count() : null,
            'items' => $items,
        ]);
    }
}
