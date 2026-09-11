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
        // Su Deposu / Sabit Boru gibi whole_unit kategoriler için kullanıcının
        // Eşleştirme adımında AÇIKÇA "envanterime ekle" dediği kategori
        // listesi (bkz. upload.vue: detectedNewCategories/newCategoryApprovals).
        // Rapor tek başına envanter için kaynak sayılamaz — bir kategori burada
        // yoksa ve zaten kayıtlı da değilse, o kategori için YENİ bir Sistem
        // Bileşeni ASLA otomatik açılmaz (bkz. aşağıdaki whole_unit dalı).
        $approvedNewCategories = array_map('strval', $data['approved_new_categories'] ?? []);

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
            $report = DB::transaction(function () use ($locationBusinessEntity, $data, $actingUser, $findingsInput, $controlItemsInput, $coveredInventoryItemIds, $approvedNewCategories, $tenantId, $file, $additionalFiles, $existingReportId, &$filePath, &$storedAdditionalPaths, &$staleFilePath, &$staleAdditionalPaths) {
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

                    // Uygunluk artık bu raporun KONTROL MADDELERİNDEN türetiliyor
                    // (bkz. FireSuppressionInventoryService::recomputeNonconformityStatus) —
                    // bu raporun eski kontrol maddelerinin bağlı olduğu kalemler,
                    // alttaki kayıtlar silindikten sonra yeniden hesaplanmalı
                    // (o kalemin "son raporu" artık değişmiş olabilir).
                    $affectedByOldControlItems = $report->controlItems()
                        ->whereNotNull('inventory_item_id')
                        ->pluck('inventory_item_id')
                        ->unique();

                    // Rapor "düzeltilmiş/yeniden yüklenmiş" kabul edilir —
                    // eski çocuk kayıtlar kısmi birleştirme yapılmadan
                    // tamamen silinip aşağıda yeni veriyle sıfırdan kurulur.
                    $report->findings()->delete();
                    $report->controlItems()->delete();
                    $report->files()->delete();
                    $report->inventoryItems()->detach();
                    $report->update($attributes);

                    foreach ($affectedByOldControlItems as $itemId) {
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

                // Rapor satırının "equipment_code"u (örn. "YD15"), kalıcı
                // Sistem Bileşeni kaydındaki AYNI kategori + AYNI koda sahip
                // bir satırla TAM eşleşiyorsa bağlanır. Eşleşme bulunamazsa
                // burada YİNE DE otomatik yeni bir Sistem Bileşeni kaydı
                // açılır — AMA rapor tek başına envanter için kaynak
                // sayılamayacağı için "onay istemeden" davranış artık BURADA
                // DEĞİL, frontend'de (upload.vue) uygulanıyor: sihirbazın
                // Eşleştirme adımında "yeni" (envanterde karşılığı olmayan)
                // her ekipman için kullanıcıya onay checkbox'ı gösterilir
                // (varsayılan işaretli), onaylanmayanların equipment_code'u
                // bu isteğe HİÇ dahil edilmez — yani bu satıra hiç ulaşmaz.
                // Kısacası: buraya kadar gelen her control_item zaten
                // kullanıcı onaylı sayılır, backend'in ayrıca bir onay
                // kontrolü yapmasına gerek yoktur.
                $componentIdByKey = FireSuppressionInventoryItem::query()
                    ->where('location_business_entity_id', $locationBusinessEntity->id)
                    ->whereNotNull('code')
                    ->get(['id', 'category', 'code'])
                    ->mapWithKeys(fn (FireSuppressionInventoryItem $item) => [
                        $item->category . '|' . mb_strtolower(trim($item->code), 'UTF-8') => $item->id,
                    ]);

                // Su Deposu / Sabit Boru gibi whole_unit kategorilerde equipment_code
                // hiç OLMAZ (bkz. FireSuppressionInventoryItem::UNIT_SCOPES notu —
                // içinde ayrı ayrı sayılan alt-birim yok, tek bir kayıt tüm
                // kategoriyi temsil eder). Bu satırlar YUKARIDAKİ koda-göre eşleştirmeye
                // hiç girmez — kategori başına TEK kayıt, sadece kategoriye göre
                // eşleştirilir/oluşturulur. YENİ bir whole_unit kaydı ise per_unit'in
                // aksine kod bazlı değil KATEGORİ bazlı onaya tabidir — bkz.
                // $approvedNewCategories ve aşağıdaki elseif dalı.
                $wholeUnitIdByCategory = FireSuppressionInventoryItem::query()
                    ->where('location_business_entity_id', $locationBusinessEntity->id)
                    ->whereNull('code')
                    ->get(['id', 'category'])
                    ->mapWithKeys(fn (FireSuppressionInventoryItem $item) => [$item->category => $item->id])
                    ->all();

                $touchedInventoryItemIds = [];

                foreach ($controlItemsInput as $index => $controlItem) {
                    $inventoryItemId = $controlItem['inventory_item_id'] ?? null;
                    $equipmentCode = $controlItem['equipment_code'] ?? null;
                    $category = $controlItem['category'] ?? null;

                    if ($inventoryItemId === null && $equipmentCode !== null && $category !== null) {
                        $key = $category . '|' . mb_strtolower(trim($equipmentCode), 'UTF-8');
                        $inventoryItemId = $componentIdByKey[$key] ?? null;

                        if ($inventoryItemId === null) {
                            $newComponent = FireSuppressionInventoryItem::query()->create([
                                'tenant_id' => $tenantId,
                                'location_business_entity_id' => $locationBusinessEntity->id,
                                'category' => $category,
                                'unit_scope' => 'per_unit',
                                'code' => $equipmentCode,
                            ]);

                            $inventoryItemId = $newComponent->id;
                            $componentIdByKey[$key] = $inventoryItemId;
                        }
                    } elseif ($inventoryItemId === null && $equipmentCode === null && $category !== null) {
                        $inventoryItemId = $wholeUnitIdByCategory[$category] ?? null;

                        // Kategori zaten kayıtlıysa yukarıda bulunur, sorun yok.
                        // Kayıtlı DEĞİLSE: SADECE kullanıcı Eşleştirme adımında bu
                        // kategoriyi "envantere ekle" diye AÇIKÇA onayladıysa yeni
                        // bir Sistem Bileşeni açılır. Onaylanmadıysa inventory_item_id
                        // null kalır — kontrol maddesi denetim izi için rapora yine de
                        // yazılır, ama hiçbir kayıtlı bileşene bağlı olmadığı için
                        // Tesisat Durumu ekranında (ana ekran) hiç görünmez.
                        if ($inventoryItemId === null && in_array($category, $approvedNewCategories, true)) {
                            $newComponent = FireSuppressionInventoryItem::query()->create([
                                'tenant_id' => $tenantId,
                                'location_business_entity_id' => $locationBusinessEntity->id,
                                'category' => $category,
                                'unit_scope' => 'whole_unit',
                                'code' => null,
                            ]);

                            $inventoryItemId = $newComponent->id;
                            $wholeUnitIdByCategory[$category] = $inventoryItemId;
                        }
                    }

                    FireSuppressionReportControlItem::query()->create([
                        'tenant_id' => $tenantId,
                        'report_id' => $report->id,
                        'template_id' => $controlItem['template_id'] ?? null,
                        'equipment_code' => $equipmentCode,
                        'inventory_item_id' => $inventoryItemId,
                        'category' => $category,
                        'code' => $controlItem['code'] ?? null,
                        'section' => $controlItem['section'] ?? null,
                        'title' => $controlItem['title'],
                        'status' => $controlItem['status'],
                        'description' => $controlItem['description'] ?? null,
                        'sort_order' => $index,
                    ]);

                    if ($inventoryItemId !== null) {
                        $touchedInventoryItemIds[] = $inventoryItemId;
                    }
                }

                // Uygunluk (compliance_status / open_nonconformity_count),
                // bulgulardan DEĞİL, bu raporun kontrol maddesi (matris)
                // satırlarından türetilir — bir kalemin bir tane bile UD
                // maddesi varsa o kalem uygunsuzdur, bitti (bulgular sadece
                // UD maddenin açıklama metnidir, ayrı bir uygunluk kaynağı
                // değildir).
                foreach (array_unique($touchedInventoryItemIds) as $itemId) {
                    $item = FireSuppressionInventoryItem::query()->find($itemId);
                    if ($item) {
                        $this->inventoryService->recomputeNonconformityStatus($item);
                    }
                }

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
                        // NOT: affectedItems sync'i sadece "Uygunsuzluklar"
                        // sekmesinin gösterdiği, bulguya bağlı bileşen
                        // listesi içindir — compliance_status artık bundan
                        // türetilmiyor (yukarıda kontrol maddelerinden
                        // hesaplandı), bu yüzden burada ayrıca recompute
                        // TETİKLENMİYOR.
                        $finding->affectedItems()->sync($resolvedIds);
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
        // sadece raporun kendisi (findings + control_items + pivot
        // ilişkileri cascade ile) kaldırılır, FireSuppressionInventoryItem'a
        // hiç dokunulmaz. Ama bu rapordaki kontrol maddelerinin bağlı olduğu
        // kalemlerin uygunluk durumu artık güncel değildir (bu raporun
        // verisine dayanıyordu) — cascade'den ÖNCE etkilenen id'leri toplayıp
        // silme sonrası yeniden hesaplanır.
        $affectedItemIds = $report->controlItems()
            ->whereNotNull('inventory_item_id')
            ->pluck('inventory_item_id')
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
