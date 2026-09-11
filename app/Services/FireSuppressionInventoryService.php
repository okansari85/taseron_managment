<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\FireSuppressionInventoryItem;
use App\Models\FireSuppressionReport;
use App\Models\FireSuppressionReportControlItem;
use App\Models\LocationBusinessEntity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Illuminate\Validation\ValidationException;

class FireSuppressionInventoryService
{
    public function __construct(
        private TenantContext $tenantContext
    ) {
    }

    public function all(LocationBusinessEntity $locationBusinessEntity): Collection
    {
        $this->assertEntityTenant($locationBusinessEntity);

        return FireSuppressionInventoryItem::query()
            ->where('location_business_entity_id', $locationBusinessEntity->id)
            ->orderBy('category')
            ->orderBy('code')
            ->get();
    }

    // AI rapor analizinin çıkardığı ekipman kodlarını mevcut envanterle
    // eşleştirir — kesin eşleşme (section 9: "Rapor: YSC-001, Envanter:
    // YSC-001 → otomatik eşleşir"), deterministic, AI gerektirmez.
    public function matchByCodes(LocationBusinessEntity $locationBusinessEntity, array $codes): Collection
    {
        $this->assertEntityTenant($locationBusinessEntity);

        $codes = array_values(array_filter(array_unique($codes)));

        if ($codes === []) {
            return new Collection();
        }

        return FireSuppressionInventoryItem::query()
            ->where('location_business_entity_id', $locationBusinessEntity->id)
            ->whereIn('code', $codes)
            ->get();
    }

    // Envanter ana ekranındaki "Genel Durum" + kategori kartları için — section
    // 8/9. Genel uygunluk kararı burada TEK ve deterministik bir kurala
    // bağlanır: aktif bir kalemin compliance_status'u 'uygun_degil' ise genel
    // durum 'uygun_degil'dir, aksi halde 'uygun'dur (null/bilinmeyen bir ihlal
    // sayılmaz — rapor pipeline'ı henüz yokken her şeyi "uygunsuz" göstermemek
    // için).
    public function summary(LocationBusinessEntity $locationBusinessEntity): array
    {
        $items = $this->all($locationBusinessEntity)->where('is_active', true);

        $overallStatus = $items->contains(fn (FireSuppressionInventoryItem $item) => $item->compliance_status === 'uygun_degil')
            ? 'uygun_degil'
            : 'uygun';

        $lastControlDate = $items
            ->pluck('last_control_date')
            ->filter()
            ->sort()
            ->last();

        // "Kaç madde uygunsuz" — kategorinin SON raporundaki UD kontrol
        // maddesi satırlarından, madde KODU bazında TEKİL sayılır (aynı madde
        // 20 dolapta da UD ise 1 sayılır — bileşen sayısı değil, madde sayısı).
        $latestReport = FireSuppressionReport::query()
            ->where('location_business_entity_id', $locationBusinessEntity->id)
            ->orderByDesc('report_date')
            ->first();

        $nonconformingCodesByCategory = collect();
        if ($latestReport) {
            $nonconformingCodesByCategory = FireSuppressionReportControlItem::query()
                ->where('report_id', $latestReport->id)
                ->where('status', 'uygun_degil')
                ->get(['category', 'code'])
                ->groupBy('category')
                ->map(fn ($rows) => $rows->pluck('code')->filter()->unique()->count());
        }

        // Section: sistem bileşenleri proje bazlı belirlenir, biz önceden
        // bilemeyiz — bu yüzden bir kategoride HİÇ kayıt/rapor verisi olmasa
        // bile o kategori listeden ASLA düşürülmez (total: 0 olarak kalır,
        // arayüzde "Raporda Yok" gösterilir).
        $categories = collect(FireSuppressionInventoryItem::CATEGORIES)
            ->map(function (string $category) use ($items, $nonconformingCodesByCategory) {
                $categoryItems = $items->where('category', $category);

                return [
                    'category' => $category,
                    'total' => $categoryItems->count(),
                    // Kaç bileşen/dolap uygunsuz — TEKİL kalem sayısı ("147
                    // dolap var, 30'u uygunsuz" buradaki 30).
                    'nonconforming_component_count' => $categoryItems->where('compliance_status', 'uygun_degil')->count(),
                    // Kaç kontrol maddesi uygunsuz — TEKİL madde kodu sayısı.
                    'nonconforming_control_item_count' => $nonconformingCodesByCategory->get($category, 0),
                ];
            })
            ->values();

        return [
            'overall_status' => $overallStatus,
            'total_equipment' => $items->count(),
            'total_nonconforming_components' => $items->where('compliance_status', 'uygun_degil')->count(),
            'last_control_date' => $lastControlDate?->toDateString(),
            'categories' => $categories,
        ];
    }

    // "Tesisat Durumu > Sistem" detay ekranı için — bir kategorinin KALICI
    // bileşen kayıtlarını (Bileşenler sekmesi) SON raporun o kategoriye ait
    // kontrol maddeleri/bulgularıyla (Kontroller/Uygunsuzluklar sekmeleri)
    // birlikte döner. İkisi AYRI kaynaktır — bileşenler rapordan bağımsız
    // kalıcıdır, kontrol/bulgu verisi sadece son raporun o anki kaydıdır
    // (bkz. proje mimari kararı: "rapor tesisatı oluşturmaz").
    public function componentDetail(LocationBusinessEntity $locationBusinessEntity, string $category): array
    {
        $this->assertEntityTenant($locationBusinessEntity);
        $this->assertCategory($category);

        $components = $this->all($locationBusinessEntity)->where('category', $category)->values();

        $latestReport = FireSuppressionReport::query()
            ->where('location_business_entity_id', $locationBusinessEntity->id)
            ->orderByDesc('report_date')
            ->with([
                'controlItems' => fn ($q) => $q->where('category', $category)->orderBy('sort_order'),
                'findings' => fn ($q) => $q->where('category', $category),
            ])
            ->first();

        return [
            'category' => $category,
            'components' => $components,
            'control_items' => $latestReport?->controlItems ?? collect(),
            'findings' => $latestReport?->findings ?? collect(),
            'report' => $latestReport ? [
                'id' => $latestReport->id,
                'report_date' => $latestReport->report_date?->toDateString(),
                'report_no' => $latestReport->report_no,
            ] : null,
        ];
    }

    public function create(LocationBusinessEntity $locationBusinessEntity, array $data): FireSuppressionInventoryItem
    {
        $this->assertEntityTenant($locationBusinessEntity);

        if (! $this->tenantContext->has()) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        $this->assertCategory($data['category']);

        return DB::transaction(function () use ($locationBusinessEntity, $data) {
            return FireSuppressionInventoryItem::query()->create([
                'tenant_id' => $this->tenantContext->id(),
                'location_business_entity_id' => $locationBusinessEntity->id,
                'category' => $data['category'],
                // Kod verilmemişse (Su Deposu, Sabit Boru gibi elle eklenen
                // whole_unit bir sistem) bunu açıkça işaretliyoruz — rapor
                // pipeline'ındaki (FireSuppressionReportService::create())
                // aynı kural burada da geçerli: kodsuz kayıt = kategori
                // başına TEK, bütünsel bileşen.
                'unit_scope' => ($data['code'] ?? null) === null ? 'whole_unit' : 'per_unit',
                'code' => $data['code'] ?? null,
                'display_name' => $data['display_name'] ?? null,
                'location_note' => $data['location_note'] ?? null,
                'brand' => $data['brand'] ?? null,
                'model' => $data['model'] ?? null,
                'serial_no' => $data['serial_no'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'last_control_date' => $data['last_control_date'] ?? null,
                'next_control_date' => $data['next_control_date'] ?? null,
                'compliance_status' => $data['compliance_status'] ?? null,
                'open_nonconformity_count' => $data['open_nonconformity_count'] ?? 0,
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }

    public function update(FireSuppressionInventoryItem $item, array $data): FireSuppressionInventoryItem
    {
        $this->assertOwnership($item);

        if (array_key_exists('category', $data)) {
            $this->assertCategory($data['category']);
        }

        $item->update($data);

        return $item->refresh();
    }

    // $force=true, "rapor/kontrol geçmişi var" korumasını atlayıp kalemi
    // yine de siler — kullanıcı bilerek geçmişi göz ardı etmek istediğinde
    // (örn. yanlış eklenmiş bir sistemi tamamen kaldırmak). DB tarafında bu
    // güvenli: fire_suppression_report_control_items.inventory_item_id
    // nullOnDelete, fire_suppression_report_inventory_items ve
    // fire_suppression_report_finding_items cascadeOnDelete — rapor/kontrol
    // satırlarının kendisi silinmez, sadece bu kaleme olan bağlantısı düşer.
    public function delete(FireSuppressionInventoryItem $item, bool $force = false): void
    {
        $this->assertOwnership($item);

        if (! $force && $item->reports()->exists()) {
            throw new RuntimeException('Bu envanter kaydının rapor/kontrol geçmişi var, silinemez. Bunun yerine pasife alabilirsiniz.');
        }

        $item->delete();
    }

    // Rapor pipeline'ının envanteri güncellediği TEK nokta — mevcut CRUD
    // (create/update) metodlarına dokunulmadan, sadece bir raporun kapsadığı
    // kalemlerin kontrol tarihlerini ilerletmek için eklendi (section 7/10).
    // FireSuppressionReportService dışından çağrılmaz.
    public function applyControlResult(FireSuppressionInventoryItem $item, ?string $lastControlDate, ?string $nextControlDate): FireSuppressionInventoryItem
    {
        $this->assertOwnership($item);

        $item->update(array_filter([
            'last_control_date' => $lastControlDate,
            'next_control_date' => $nextControlDate,
        ], fn ($value) => $value !== null));

        return $item->refresh();
    }

    // compliance_status / open_nonconformity_count'u bu kalemin KENDİ kontrol
    // maddesi (matris) satırlarından türetir — "bulgular" (findings) bağımsız
    // bir uygunluk kaynağı DEĞİLDİR, sadece bir UD maddenin açıklama metnidir
    // (kullanıcı kararı). Kural basit: bu kalemin SON raporundaki kontrol
    // maddesi satırlarından bir tanesi bile 'uygun_degil' ise kalem
    // 'uygun_degil'dir — bitti. "Son rapor" ile sınırlanır ki eski/arşivlenmiş
    // bir raporun UD'si, kalem daha sonra uygun çıkmış olsa bile sonsuza kadar
    // uygunsuz göstermesin. Sadece FireSuppressionReportService tarafından,
    // bir rapor oluşturulduğunda/silindiğinde çağrılır.
    public function recomputeNonconformityStatus(FireSuppressionInventoryItem $item): FireSuppressionInventoryItem
    {
        $this->assertOwnership($item);

        $latestReportId = DB::table('fire_suppression_report_control_items')
            ->join('fire_suppression_reports', 'fire_suppression_reports.id', '=', 'fire_suppression_report_control_items.report_id')
            ->where('fire_suppression_report_control_items.inventory_item_id', $item->id)
            ->orderByDesc('fire_suppression_reports.report_date')
            ->orderByDesc('fire_suppression_reports.id')
            ->value('fire_suppression_reports.id');

        if ($latestReportId === null) {
            $item->update(['open_nonconformity_count' => 0, 'compliance_status' => null]);

            return $item->refresh();
        }

        $openCount = DB::table('fire_suppression_report_control_items')
            ->where('report_id', $latestReportId)
            ->where('inventory_item_id', $item->id)
            ->where('status', 'uygun_degil')
            ->count();

        $item->update([
            'open_nonconformity_count' => $openCount,
            'compliance_status' => $openCount > 0 ? 'uygun_degil' : 'uygun',
        ]);

        return $item->refresh();
    }

    private function assertOwnership(FireSuppressionInventoryItem $item): void
    {
        if (! $this->tenantContext->has()) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        if ($item->tenant_id !== $this->tenantContext->id()) {
            throw new RuntimeException('Bu envanter kaydına erişim yetkiniz yok.');
        }
    }

    private function assertCategory(string $category): void
    {
        if (! in_array($category, FireSuppressionInventoryItem::CATEGORIES, true)) {
            throw ValidationException::withMessages([
                'category' => 'Geçersiz kategori.',
            ]);
        }
    }

    private function assertEntityTenant(LocationBusinessEntity $locationBusinessEntity): void
    {
        $tenantId = $locationBusinessEntity->location?->tenant_id
            ?? $locationBusinessEntity->location()->value('tenant_id');

        if ($tenantId !== $this->tenantContext->id()) {
            throw ValidationException::withMessages([
                'location_business_entity' => 'Bu kayıt mevcut tenant kapsamında değil.',
            ]);
        }
    }
}
