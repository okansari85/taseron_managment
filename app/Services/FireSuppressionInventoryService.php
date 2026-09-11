<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\FireSuppressionInventoryItem;
use App\Models\FireSuppressionReport;
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

        $categories = collect(FireSuppressionInventoryItem::CATEGORIES)
            ->map(function (string $category) use ($items) {
                $categoryItems = $items->where('category', $category);

                return [
                    'category' => $category,
                    'total' => $categoryItems->count(),
                    'nonconformity_count' => $categoryItems->sum('open_nonconformity_count'),
                ];
            })
            ->filter(fn (array $row) => $row['total'] > 0)
            ->values();

        return [
            'overall_status' => $overallStatus,
            'total_equipment' => $items->count(),
            'total_nonconformity' => $items->sum('open_nonconformity_count'),
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
                'code' => $data['code'] ?? null,
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

    public function delete(FireSuppressionInventoryItem $item): void
    {
        $this->assertOwnership($item);

        if ($item->reports()->exists()) {
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

    // compliance_status / open_nonconformity_count'u o kalemi etkileyen AÇIK
    // rapor uygunsuzluklarından türetir (section 8: rapor kaynaklı otomatik
    // güncelleme — section 5'teki "kullanıcı onayı" burada zaten sağlanmış
    // sayılır çünkü bu aşamada uygunsuzluk elle/kullanıcı tarafından
    // girilmektedir, AI çıkarımı yok). Sadece FireSuppressionReportService
    // tarafından, bir finding oluşturulduğunda/kapatıldığında çağrılır.
    public function recomputeNonconformityStatus(FireSuppressionInventoryItem $item): FireSuppressionInventoryItem
    {
        $this->assertOwnership($item);

        $openCount = DB::table('fire_suppression_report_finding_items')
            ->join('fire_suppression_report_findings', 'fire_suppression_report_findings.id', '=', 'fire_suppression_report_finding_items.finding_id')
            ->where('fire_suppression_report_finding_items.inventory_item_id', $item->id)
            ->where('fire_suppression_report_findings.status', 'open')
            ->distinct('fire_suppression_report_findings.id')
            ->count('fire_suppression_report_findings.id');

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
