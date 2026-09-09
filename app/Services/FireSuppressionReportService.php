<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\FireSuppressionInventoryItem;
use App\Models\FireSuppressionReport;
use App\Models\FireSuppressionReportFinding;
use App\Models\LocationBusinessEntity;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;
use Throwable;

class FireSuppressionReportService
{
    private const FILE_DIRECTORY = 'fire-suppression-reports';

    public function __construct(
        private TenantContext $tenantContext,
        private FireSuppressionInventoryService $inventoryService,
    ) {
    }

    public function all(LocationBusinessEntity $locationBusinessEntity): Collection
    {
        $this->assertEntityTenant($locationBusinessEntity);

        $reports = FireSuppressionReport::query()
            ->where('location_business_entity_id', $locationBusinessEntity->id)
            ->withCount('findings')
            ->with('uploadedByUser:id,name')
            ->orderByDesc('report_date')
            ->get();

        // Section 26: en güncel rapor (report_date'e göre) "Güncel", geri
        // kalanı "Geçmiş/Arşiv" — kalıcı bir durum kolonu yerine listeleme
        // anında türetilir, böylece hep tutarlıdır.
        return $reports->values()->map(function (FireSuppressionReport $report, int $index) {
            $report->setAttribute('is_current', $index === 0);

            return $report;
        });
    }

    public function find(FireSuppressionReport $report): FireSuppressionReport
    {
        $this->assertOwnership($report);

        return $report->load(['findings.affectedItems', 'inventoryItems', 'uploadedByUser:id,name']);
    }

    public function create(LocationBusinessEntity $locationBusinessEntity, array $data, UploadedFile $file, ?User $actingUser): FireSuppressionReport
    {
        $this->assertEntityTenant($locationBusinessEntity);

        if (! $this->tenantContext->has()) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        $tenantId = $this->tenantContext->id();
        $findingsInput = $data['findings'] ?? [];
        $coveredInventoryItemIds = array_map('intval', $data['covered_inventory_item_ids'] ?? []);

        $this->assertItemsBelongToBranch($locationBusinessEntity, array_unique(array_merge(
            $coveredInventoryItemIds,
            collect($findingsInput)->flatMap(fn (array $f) => $f['affected_item_ids'] ?? [])->map(fn ($id) => (int) $id)->all()
        )));

        $filePath = null;

        try {
            return DB::transaction(function () use ($locationBusinessEntity, $data, $actingUser, $findingsInput, $coveredInventoryItemIds, $tenantId, $file, &$filePath) {
                $filePath = $file->store(self::FILE_DIRECTORY, 'public');

                $report = FireSuppressionReport::query()->create([
                    'tenant_id' => $tenantId,
                    'location_business_entity_id' => $locationBusinessEntity->id,
                    'report_date' => $data['report_date'],
                    'next_control_date' => $data['next_control_date'] ?? null,
                    'covered_categories' => $data['covered_categories'] ?? null,
                    'overall_result' => $data['overall_result'] ?? null,
                    'file_path' => $filePath,
                    'file_name' => $file->getClientOriginalName(),
                    'uploaded_by_user_id' => $actingUser?->id,
                    'notes' => $data['notes'] ?? null,
                ]);

                $affectedItemIds = [];

                foreach ($findingsInput as $findingData) {
                    $finding = FireSuppressionReportFinding::query()->create([
                        'tenant_id' => $tenantId,
                        'report_id' => $report->id,
                        'category' => $findingData['category'] ?? null,
                        'control_item' => $findingData['control_item'] ?? null,
                        'description' => $findingData['description'],
                        'scope' => $findingData['scope'],
                        'area_note' => $findingData['area_note'] ?? null,
                        'status' => 'open',
                    ]);

                    $resolvedIds = $this->resolveFindingScope($locationBusinessEntity, $finding, $findingData);

                    if ($resolvedIds !== []) {
                        $finding->affectedItems()->sync($resolvedIds);
                        $affectedItemIds = [...$affectedItemIds, ...$resolvedIds];
                    }
                }

                foreach (array_unique($affectedItemIds) as $itemId) {
                    $item = FireSuppressionInventoryItem::query()->find($itemId);
                    if ($item) {
                        $this->inventoryService->recomputeNonconformityStatus($item);
                    }
                }

                foreach ($coveredInventoryItemIds as $itemId) {
                    $item = FireSuppressionInventoryItem::query()->find($itemId);
                    if ($item) {
                        $report->inventoryItems()->syncWithoutDetaching([$itemId]);
                        $this->inventoryService->applyControlResult($item, $data['report_date'], $data['next_control_date'] ?? null);
                    }
                }

                return $this->find($report);
            });
        } catch (Throwable $exception) {
            if ($filePath) {
                Storage::disk('public')->delete($filePath);
            }

            throw $exception;
        }
    }

    public function delete(FireSuppressionReport $report): void
    {
        $this->assertOwnership($report);

        // Section 25: rapor silinse de envanter kalemleri silinmez — burada
        // sadece raporun kendisi (findings + pivot ilişkileri cascade ile)
        // kaldırılır, FireSuppressionInventoryItem'a hiç dokunulmaz. Ama bu
        // rapordaki bulguların etkilediği kalemlerin uygunsuzluk durumu artık
        // güncel değildir (silinen finding'e dayanıyordu) — cascade'den ÖNCE
        // etkilenen id'leri toplayıp silme sonrası yeniden hesaplanır.
        $affectedItemIds = $report->findings()
            ->with('affectedItems:id')
            ->get()
            ->flatMap(fn ($finding) => $finding->affectedItems->pluck('id'))
            ->unique();

        $filePath = $report->file_path;
        $report->delete();

        foreach ($affectedItemIds as $itemId) {
            $item = FireSuppressionInventoryItem::query()->find($itemId);
            if ($item) {
                $this->inventoryService->recomputeNonconformityStatus($item);
            }
        }

        if ($filePath) {
            Storage::disk('public')->delete($filePath);
        }
    }

    // scope='specific' → data'dan gelen id'ler; scope='all' → aynı şube +
    // aynı kategorideki tüm aktif kalemler; scope='area'/'unknown' → section
    // 18/19 gereği HİÇBİR kalem otomatik etkilenmez, kullanıcı sonradan elle
    // bağlar (bu ekran henüz o adımı içermiyor, veri modeli hazır).
    private function resolveFindingScope(LocationBusinessEntity $locationBusinessEntity, FireSuppressionReportFinding $finding, array $findingData): array
    {
        if ($finding->scope === 'specific') {
            return array_map('intval', $findingData['affected_item_ids'] ?? []);
        }

        if ($finding->scope === 'all' && $finding->category) {
            return FireSuppressionInventoryItem::query()
                ->where('location_business_entity_id', $locationBusinessEntity->id)
                ->where('category', $finding->category)
                ->where('is_active', true)
                ->pluck('id')
                ->all();
        }

        return [];
    }

    private function assertItemsBelongToBranch(LocationBusinessEntity $locationBusinessEntity, array $itemIds): void
    {
        if ($itemIds === []) {
            return;
        }

        $count = FireSuppressionInventoryItem::query()
            ->where('location_business_entity_id', $locationBusinessEntity->id)
            ->whereIn('id', $itemIds)
            ->count();

        if ($count !== count($itemIds)) {
            throw new RuntimeException('Seçilen envanter kalemlerinden biri bu şubeye ait değil.');
        }
    }

    private function assertOwnership(FireSuppressionReport $report): void
    {
        if (! $this->tenantContext->has()) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        if ($report->tenant_id !== $this->tenantContext->id()) {
            throw new RuntimeException('Bu rapora erişim yetkiniz yok.');
        }
    }

    private function assertEntityTenant(LocationBusinessEntity $locationBusinessEntity): void
    {
        $tenantId = $locationBusinessEntity->location?->tenant_id
            ?? $locationBusinessEntity->location()->value('tenant_id');

        if ($tenantId !== $this->tenantContext->id()) {
            throw new RuntimeException('Bu şubeye erişim yetkiniz yok.');
        }
    }
}
