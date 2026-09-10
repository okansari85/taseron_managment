<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\FireSuppressionControlItemTemplate;
use App\Models\FireSuppressionInventoryItem;
use App\Models\FireSuppressionReport;
use App\Models\FireSuppressionReportControlItem;
use App\Models\FireSuppressionReportFile;
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
    private const ADDITIONAL_FILE_DIRECTORY = 'fire-suppression-reports/additional';

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

        return $report->load([
            'findings.affectedItems',
            'inventoryItems',
            'uploadedByUser:id,name',
            'controlItems' => fn ($q) => $q->orderBy('category')->orderBy('sort_order'),
            'files.uploadedByUser:id,name',
        ]);
    }

    // Rapor yükleme sihirbazının "Kontrol ve Onay" adımında, kullanıcının
    // seçtiği kategoriler için gösterilecek standart checklist — tenant'a
    // bağlı değil, sadece referans olarak okunur.
    public function controlItemTemplates(array $categories = []): Collection
    {
        return FireSuppressionControlItemTemplate::query()
            ->when($categories !== [], fn ($q) => $q->whereIn('category', $categories))
            ->orderBy('category')
            ->orderBy('sort_order')
            ->get();
    }

    // $additionalFiles: [['file' => UploadedFile, 'type' => 'fotograf'|'ek_belge'|'diger', 'description' => ?string], ...]
    //
    // Aynı şubede AYNI rapor numarasıyla tekrar yükleme — yıllık zorunlu
    // kontrolde her yıl FARKLI bir rapor no gelir (o zaman yeni bir arşiv
    // kaydıdır), ama AYNI rapor no tekrar geldiyse bu yasal olarak AYNI
    // rapordur (yeniden yükleme/düzeltme) — yeni bir arşiv satırı değil,
    // mevcut raporun GÜNCELLENMESİDİR: eski bulgu/madde/ek dosya/dosya
    // tamamen yeni yüklenenle değiştirilir.
    public function create(LocationBusinessEntity $locationBusinessEntity, array $data, UploadedFile $file, ?User $actingUser, array $additionalFiles = []): FireSuppressionReport
    {
        $this->assertEntityTenant($locationBusinessEntity);

        if (! $this->tenantContext->has()) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        $tenantId = $this->tenantContext->id();
        $findingsInput = $data['findings'] ?? [];
        $controlItemsInput = $data['control_items'] ?? [];
        $coveredInventoryItemIds = array_map('intval', $data['covered_inventory_item_ids'] ?? []);

        $this->assertItemsBelongToBranch($locationBusinessEntity, array_unique(array_merge(
            $coveredInventoryItemIds,
            collect($findingsInput)->flatMap(fn (array $f) => $f['affected_item_ids'] ?? [])->map(fn ($id) => (int) $id)->all()
        )));

        $existingReportId = ! empty($data['report_no'])
            ? FireSuppressionReport::query()
                ->where('location_business_entity_id', $locationBusinessEntity->id)
                ->where('report_no', $data['report_no'])
                ->value('id')
            : null;

        $filePath = null;
        $storedAdditionalPaths = [];
        $staleFilePath = null;
        $staleAdditionalPaths = [];

        try {
            $report = DB::transaction(function () use ($locationBusinessEntity, $data, $actingUser, $findingsInput, $controlItemsInput, $coveredInventoryItemIds, $tenantId, $file, $additionalFiles, $existingReportId, &$filePath, &$storedAdditionalPaths, &$staleFilePath, &$staleAdditionalPaths) {
                $filePath = $file->store(self::FILE_DIRECTORY, 'public');

                $attributes = [
                    'tenant_id' => $tenantId,
                    'location_business_entity_id' => $locationBusinessEntity->id,
                    'report_date' => $data['report_date'],
                    'report_no' => $data['report_no'] ?? null,
                    'next_control_date' => $data['next_control_date'] ?? null,
                    'covered_categories' => $data['covered_categories'] ?? null,
                    'overall_result' => $data['overall_result'] ?? null,
                    'inspection_company_name' => $data['inspection_company_name'] ?? null,
                    'file_path' => $filePath,
                    'file_name' => $file->getClientOriginalName(),
                    'uploaded_by_user_id' => $actingUser?->id,
                    'notes' => $data['notes'] ?? null,
                ];

                if ($existingReportId) {
                    $report = FireSuppressionReport::query()->findOrFail($existingReportId);
                    $staleFilePath = $report->file_path;
                    $staleAdditionalPaths = $report->files()->pluck('file_path')->all();

                    // Bu raporun eski bulgularının etkilediği envanter
                    // kalemlerinin uygunsuzluk durumu, alttaki kayıtlar
                    // silinip yeniden kurulduktan sonra yeniden hesaplanmalı.
                    $affectedByOldFindings = $report->findings()
                        ->with('affectedItems:id')
                        ->get()
                        ->flatMap(fn ($finding) => $finding->affectedItems->pluck('id'))
                        ->unique();

                    // Rapor "düzeltilmiş/yeniden yüklenmiş" kabul edilir —
                    // eski çocuk kayıtlar kısmi birleştirme yapılmadan
                    // tamamen silinip aşağıda yeni veriyle sıfırdan kurulur.
                    $report->findings()->delete();
                    $report->controlItems()->delete();
                    $report->files()->delete();
                    $report->inventoryItems()->detach();
                    $report->update($attributes);

                    foreach ($affectedByOldFindings as $itemId) {
                        $item = FireSuppressionInventoryItem::query()->find($itemId);
                        if ($item) {
                            $this->inventoryService->recomputeNonconformityStatus($item);
                        }
                    }
                } else {
                    $report = FireSuppressionReport::query()->create($attributes);
                }

                foreach ($additionalFiles as $index => $additional) {
                    $storedPath = $additional['file']->store(self::ADDITIONAL_FILE_DIRECTORY, 'public');
                    $storedAdditionalPaths[] = $storedPath;

                    FireSuppressionReportFile::query()->create([
                        'tenant_id' => $tenantId,
                        'report_id' => $report->id,
                        'file_type' => $additional['type'],
                        'file_path' => $storedPath,
                        'file_name' => $additional['file']->getClientOriginalName(),
                        'file_size' => $additional['file']->getSize(),
                        'description' => $additional['description'] ?? null,
                        'uploaded_by_user_id' => $actingUser?->id,
                    ]);
                }

                foreach ($controlItemsInput as $index => $controlItem) {
                    FireSuppressionReportControlItem::query()->create([
                        'tenant_id' => $tenantId,
                        'report_id' => $report->id,
                        'template_id' => $controlItem['template_id'] ?? null,
                        'equipment_code' => $controlItem['equipment_code'] ?? null,
                        'inventory_item_id' => $controlItem['inventory_item_id'] ?? null,
                        'category' => $controlItem['category'] ?? null,
                        'code' => $controlItem['code'] ?? null,
                        'section' => $controlItem['section'] ?? null,
                        'title' => $controlItem['title'],
                        'status' => $controlItem['status'],
                        'description' => $controlItem['description'] ?? null,
                        'sort_order' => $index,
                    ]);
                }

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

            // Eski dosyalar transaction BAŞARIYLA bittikten sonra silinir —
            // transaction içinde silinirse ve sonradan rollback olursa geri
            // getirilemez.
            if ($staleFilePath) {
                Storage::disk('public')->delete($staleFilePath);
            }

            if ($staleAdditionalPaths !== []) {
                Storage::disk('public')->delete($staleAdditionalPaths);
            }

            return $report;
        } catch (Throwable $exception) {
            if ($filePath) {
                Storage::disk('public')->delete($filePath);
            }

            if ($storedAdditionalPaths !== []) {
                Storage::disk('public')->delete($storedAdditionalPaths);
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
        $additionalPaths = $report->files()->pluck('file_path')->all();
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

        if ($additionalPaths !== []) {
            Storage::disk('public')->delete($additionalPaths);
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
