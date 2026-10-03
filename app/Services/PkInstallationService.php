<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Location;
use App\Models\LocationBusinessEntity;
use App\Models\PeriodicEquipmentType;
use App\Models\PeriodicInstallationSystem;
use App\Models\PkEquipment;
use App\Models\PkInspection;
use App\Models\PkInstallation;
use App\Models\PkInstallationReport;
use App\Models\PkInstallationReportComponent;
use App\Models\PkInstallationReportSystem;
use App\Models\PkInstallationSystem;
use App\Models\User;
use App\Services\Ai\PkTakip\PkReportEquipmentMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * pktakip tesisatları (Yangın Tesisatı, Yangın Algılama ve Uyarı Sistemleri): tesisat → sistemler → kontrol geçmişi.
 * Ekipman kategorisi gibi: tesisat raporsuz eklenir, sistemleri katalogdan seçilir; sistemin ekipmanları Ekipmanlar'daki
 * kayıtlardır (sistemin ekipman türü, aynı lokasyon). Farkı: tesisatın genel durumu ve tarihleri son tesisat raporunun
 * genel sonucundan gelir; sistem raporları (dolap, pano…) yalnızca kendi sistemlerini günceller. Sistemlerin durumu ve
 * bulguları, sistemin geçtiği en son kontrolden.
 */
class PkInstallationService
{
    // Tesisatlar menüsündeki türler (sırasıyla); yapay zekanın seçtiği slug bunlardan biriyle eşleşir.
    public const TYPE_SLUGS = ['yangin-tesisati', 'yangin-algilama'];

    // Yangının yapay zeka talimatına girmeyen türler: Tesisatlar menüsünde sekme, tesisat ekleme ve kontrol. Yangının tesisat
    // talimatı TYPE_SLUGS'tan (types) kurulur; bunlar oraya girmez (elektrik ailesi kendi talimatıyla okunur).
    public const MANUAL_TYPE_SLUGS = ['elektrik-ic-tesisati', 'topraklama', 'havalandirma-klima', 'paratoner', 'akumulator', 'trafo'];

    // Elektrik iç tesisatı: kendi yapay zeka talimatı (PkElectricalReportAnalyzer); rapordaki ekipmanlar Ekipmanlar'a eklenmez.
    public const ELECTRICAL_TYPE_SLUG = 'elektrik-ic-tesisati';

    // Elektrik ailesi (her birinin kendi talimatı; okuma, kayıt ve rapordaki ekipmanlar ortak): elektrik iç tesisatı, topraklama,
    // yangın algılama ve uyarı sistemleri (yangın tesisatının okumasıyla değil, kendi talimatıyla okunur), havalandırma ve klima,
    // paratoner (yıldırımdan korunma), akümülatör / UPS, trafo merkezi. Cihazları / bileşenleri Ekipmanlar'a eklenmez.
    public const ELECTRICAL_FAMILY_SLUGS = ['elektrik-ic-tesisati', 'topraklama', 'yangin-algilama', 'havalandirma-klima', 'paratoner', 'akumulator', 'trafo'];

    // Trafo merkezi: raporlar çoğunlukla ekipman başına (trafo, kesici, hücre) gelir; genel durumu hepsinin toplamı
    // (transformerOverview).
    public const TRANSFORMER_TYPE_SLUG = 'trafo';

    // Elektrik ailesi "Tesis Özellikleri" bilgi kartı: tesisat raporundaki tesis bilgileri (okuma kodu oluşturur; yapay zekaya
    // sistem olarak verilmez).
    public const FACILITY_SYSTEM_SLUGS = ['elk-tesis-ozellikleri', 'toprak-tesis-ozellikleri', 'algilama-tesis-ozellikleri', 'hvac-tesis-ozellikleri', 'paratoner-tesis-ozellikleri', 'aku-tesis-ozellikleri', 'trafo-tesis-ozellikleri'];

    // Genel bilgilerin altında kart olarak gösterilen sistemler (tesisatta varsa; otomatik eklenmez).
    public const CARD_SYSTEM_SLUGS = ['belge-kayit', 'proje-bilgileri', 'algilama-belge-kayit', 'algilama-proje-bilgileri', 'hvac-proje-bilgileri', ...self::FACILITY_SYSTEM_SLUGS];

    public function __construct(
        private TenantContext $tenantContext,
        private PkEquipmentService $equipment,
        private PkInstallationSystemRequestService $systemRequests,
        private PkEquipmentPropertyService $properties,
        private PkReportAnalysisHistory $history
    ) {
    }

    /** Desteklenen tesisat türleri; her birinde "systems" ilişkisi: katalogdaki sistemleri. */
    public function types(): Collection
    {
        $types = PeriodicEquipmentType::query()->whereIn('slug', self::TYPE_SLUGS)->get()
            ->sortBy(fn (PeriodicEquipmentType $type) => array_search($type->slug, self::TYPE_SLUGS, true))->values();
        $systems = PeriodicInstallationSystem::query()->whereIn('installation_type_id', $types->pluck('id'))->where('is_active', true)
            ->with('equipmentTypes')->orderBy('sort_order')->get()->groupBy('installation_type_id');

        return $types->each(fn (PeriodicEquipmentType $type) => $type->setRelation('systems', $systems->get($type->id, collect())->values()));
    }

    /** Tesisatlar menüsündeki bütün türler: yapay zeka ile okunanlar (types) + yalnızca elle girilenler; sistemleriyle. */
    public function allTypes(): Collection
    {
        $slugs = [...self::TYPE_SLUGS, ...self::MANUAL_TYPE_SLUGS];
        $types = PeriodicEquipmentType::query()->whereIn('slug', $slugs)->get()
            ->sortBy(fn (PeriodicEquipmentType $type) => array_search($type->slug, $slugs, true))->values();
        $systems = PeriodicInstallationSystem::query()->whereIn('installation_type_id', $types->pluck('id'))->where('is_active', true)
            ->with('equipmentTypes')->orderBy('sort_order')->get()->groupBy('installation_type_id');

        return $types->each(fn (PeriodicEquipmentType $type) => $type->setRelation('systems', $systems->get($type->id, collect())->values()));
    }

    /** Elektrik ailesi türleri (sistemleriyle): elektrik iç tesisatı ve topraklama; kendi talimatlarıyla okuma ve kayıt için. */
    public function electricalTypes(): Collection
    {
        return $this->allTypes()->whereIn('slug', self::ELECTRICAL_FAMILY_SLUGS)->values();
    }

    /**
     * Bütün tesisat türleri (Tesisatlar kategorisi + yangın türleri): yapay zeka raporun hangi tesisata ait olduğunu bunlardan
     * seçer; başka bir tesisatın raporu ilgili sekmeden yüklenmelidir (yanlış yer uyarısı).
     */
    public function tesisatTypes(): Collection
    {
        return PeriodicEquipmentType::query()->where('is_active', true)
            ->where(fn ($query) => $query->whereHas('category', fn ($category) => $category->where('slug', 'tesisatlar'))->orWhereIn('slug', self::TYPE_SLUGS))
            ->orderBy('category_id')->orderBy('sort_order')->get(['id', 'category_id', 'slug', 'name']);
    }

    // Elle giriş ve sistem seçimi için: tesisat türleri + sistemleri.
    public function catalog(): array
    {
        return [
            'types' => $this->allTypes()->map(fn (PeriodicEquipmentType $type) => [
                'id' => $type->id,
                'slug' => $type->slug,
                'name' => $type->name,
                'default_period_months' => $type->default_period_months,
                'systems' => $type->systems->map(fn (PeriodicInstallationSystem $system) => [
                    'id' => $system->id,
                    'slug' => $system->slug,
                    'name' => $system->name,
                    // Sistemin ekipman türleri (Ekipmanlar'daki türler): sistemin içinden ekipman eklerken seçilir.
                    'equipment_types' => $system->equipmentTypes->map(fn (PeriodicEquipmentType $type) => [
                        'id' => $type->id,
                        'name' => $type->name,
                        'variants' => $type->variants ?? [],
                        'variant_label' => $type->variant_label,
                        // Kimlik kuralı (ekipman tarafındaki kayıt): dolap = konum + no, diğerleri no.
                        'identity' => PkReportEquipmentMatcher::identityFields($type->slug),
                    ])->values(),
                    'card' => in_array($system->slug, self::CARD_SYSTEM_SLUGS, true),
                ])->values(),
            ])->values(),
        ];
    }

    /**
     * Lokasyon context'i: lokasyondaki tüm tesisatlar. İşyeri context'i: o işyerinin tesisatları + lokasyon geneli.
     */
    public function list(array $filters): Collection
    {
        return $this->query()
            ->when($filters['location_id'] ?? null, fn ($query, $locationId) => $query->where('location_id', $locationId))
            // Genel bakış / durum raporu: birden çok lokasyon.
            ->when(array_key_exists('location_ids', $filters), fn ($query) => $query->whereIn('location_id', (array) $filters['location_ids']))
            ->when($filters['workplace_id'] ?? null, fn ($query, $workplaceId) => $query->where(
                fn ($inner) => $inner->whereNull('location_business_entity_id')->orWhere('location_business_entity_id', $workplaceId)
            ))
            ->orderBy('id')
            ->get()
            ->map(fn (PkInstallation $installation) => $this->present($installation))
            ->values();
    }

    public function show(PkInstallation $installation): array
    {
        $installation = $this->query()->findOrFail($installation->id);
        // Son tesisat raporunun sonuç / kanaati ve hiçbir sisteme bağlı olmayan (genel) bulguları (rapor bekleniyor kaydı
        // dosyasız ve sonuçsuz; rapor gelene kadar önceki kontrolünkiler geçerli). Sistem raporları sayılmaz.
        $latest = $installation->reports()->where('status', '!=', 'rapor_bekleniyor')->whereNull('report_scope')->with('systems')
            ->orderByDesc('control_date')->orderByDesc('id')->first();
        $systemFindings = $latest?->systems->flatMap(fn (PkInstallationReportSystem $result) => (array) $result->findings)->all() ?? [];
        // Sistemin ekipmanları: Ekipmanlar'daki kayıtlar (sistemin ekipman türleri, aynı lokasyon; türlerin katalog sırasıyla).
        // İşyerine ait tesisatta o işyerininkiler + lokasyon geneli. Pasife alınanlar görünmez.
        $typeIds = fn (PkInstallationSystem $system) => $system->catalog?->equipmentTypes->pluck('id')->all() ?? [];
        $needed = $installation->systems->flatMap($typeIds)->unique()->all();
        $equipment = $needed
            ? $this->equipment->list(['location_id' => $installation->location_id, 'workplace_id' => $installation->location_business_entity_id])
                ->filter(fn (array $item) => $item['is_active'] && in_array($item['type']['id'] ?? null, $needed, true))
                ->groupBy(fn (array $item) => $item['type']['id'])
            : collect();

        return $this->present($installation) + [
            'notes' => $installation->notes,
            'report_no' => $installation->latestReport?->report_no,
            'conclusion' => $latest?->conclusion,
            'general_findings' => array_values(array_diff((array) ($latest?->findings ?? []), $systemFindings)),
            // Raporda geçip katalog onayı bekleyen sistemler.
            'pending_systems' => $this->systemRequests->pendingFor($installation),
            'systems' => $installation->systems->map(fn (PkInstallationSystem $system) => $this->systemSummary($installation, $system) + [
                'equipment' => collect($typeIds($system))->flatMap(fn (int $typeId) => $equipment->get($typeId, collect()))->values()->all(),
            ])->values(),
            'reports' => $installation->reports()->with(['creator:id,name', 'systems.system:id,name'])->withCount('inspections')
                ->orderByDesc('control_date')->orderByDesc('id')->get()
                ->map(fn (PkInstallationReport $report) => $this->presentReport($report))
                ->values(),
        ];
    }

    // Raporsuz tesisat: tür, yer, ad ve katalogdan seçilen sistemler. Kontroller sonradan eklenir.
    public function create(array $data, User $user): PkInstallation
    {
        $type = $this->allTypes()->firstWhere('id', (int) $data['installation_type_id']);
        if (!$type) {
            throw ValidationException::withMessages(['installation_type_id' => 'Tesisat türünü seçin.']);
        }
        $this->assertPlacement((int) $data['location_id'], $data['location_business_entity_id'] ?? null);
        $systemIds = $this->allowedSystems($type, (array) ($data['system_ids'] ?? []));

        return DB::transaction(function () use ($data, $type, $systemIds, $user) {
            $installation = PkInstallation::create([
                'tenant_id' => $this->tenantContext->id(),
                'location_id' => (int) $data['location_id'],
                'location_business_entity_id' => $data['location_business_entity_id'] ?? null,
                'installation_type_id' => $type->id,
                'name' => trim((string) ($data['name'] ?? '')) ?: null,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'created_by' => $user->id,
            ]);
            foreach ($systemIds as $systemId) {
                $this->resolveSystem($installation, ['system_id' => $systemId]);
            }

            return $installation;
        });
    }

    public function update(PkInstallation $installation, array $data): array
    {
        DB::transaction(function () use ($installation, $data) {
            $installation->update(Arr::only($data, ['name', 'notes']));
            if (array_key_exists('system_ids', $data)) {
                $this->syncSystems($installation, (array) ($data['system_ids'] ?? []));
            }
        });

        return $this->show($installation);
    }

    /**
     * Yapay zeka analizini kayda kadar saklar (24 saat); kayıtta analysis_id ile rapora yazılır. Hangi tesisata
     * eklenebileceği (aday) ve raporun daha önce yüklenip yüklenmediği de döner.
     */
    public function rememberAnalysis(array $result, int $locationId, ?int $workplaceId, ?string $fileHash): array
    {
        $id = (string) Str::uuid();
        Cache::put($this->analysisKey($id), [
            'tenant_id' => $this->tenantContext->id(),
            'location_id' => $locationId,
            'file_hash' => $fileHash,
            'model' => $result['fixture']['model'] ?? \App\Services\Ai\PkTakip\PkAiProvider::model(),
            'analyzed_at' => now()->toIso8601String(),
            'fixture' => $result['fixture'] ?? null,
            'duration_s' => $result['duration_s'] ?? null,
            'input' => $result['input'] ?? null,
            'summary' => Arr::except($result, ['semantic', 'tables']),
            'semantic' => $result['semantic'] ?? [],
            'tables' => $result['tables'] ?? [],
        ], now()->addDay());

        // Rapordaki ekipman satırlarının karşılığı için lokasyondaki mevcut ekipmanlar (tesisat türlerinin ekipman türlerinden;
        // pasife alınanlar önerilmez).
        $typeIds = $this->types()->flatMap(fn (PeriodicEquipmentType $type) => $type->systems->flatMap(fn (PeriodicInstallationSystem $system) => $system->equipmentTypes->pluck('id')))->unique()->all();
        $locationEquipment = $typeIds && !empty($result['equipment_rows'])
            ? $this->equipment->list(['location_id' => $locationId])
                ->filter(fn (array $item) => $item['is_active'] && in_array($item['type']['id'] ?? null, $typeIds, true))
                ->map(fn (array $item) => Arr::only($item, ['id', 'code', 'place', 'serial_no', 'brand', 'variant', 'status']) + [
                    'type_id' => $item['type']['id'],
                    'workplace' => $item['workplace']['company_name'] ?? null,
                ])
                ->values()
                ->all()
            : [];

        return Arr::except($result, ['semantic', 'tables']) + [
            'analysis_id' => $id,
            'location_equipment' => $locationEquipment,
            // Aynı dosya kaydı kapatır. Aynı rapor no'lu raporlar bilgi olarak döner: sistem raporu farklı sistemse
            // kaydedilebilir; kural kayıtta uygulanır, ekran seçilen türe göre önceden uyarır.
            'duplicate' => $this->duplicateFor(null, $fileHash, null),
            'report_no_matches' => $this->reportNoMatches($result['report_no'] ?? null),
            // Lokasyondaki tesisatlar: rapor seçilen türdekine eklenir (yoksa oluşturulur).
            'installations' => $this->list(['location_id' => $locationId, 'workplace_id' => $workplaceId])
                ->map(fn (array $item) => Arr::only($item, ['id', 'name', 'type', 'workplace', 'status', 'last_control_date']))
                ->values()
                ->all(),
        ];
    }

    // Raporun ekleneceği tesisat: lokasyondaki aynı türde tesisat (önce lokasyon geneli), yoksa null (oluşturulur).
    public function existingFor(int $locationId, int $typeId): ?PkInstallation
    {
        return PkInstallation::query()->where('location_id', $locationId)->where('installation_type_id', $typeId)
            ->orderByRaw('location_business_entity_id is not null')->orderBy('id')->first();
    }

    /**
     * Raporu kaydeder: mevcut tesisata (installation_id) ya da yeni tesisatla birlikte. Sistemler kullanıcının
     * onayladığı listeden (katalog sistemi ya da adıyla); sistem sonuçları, bulgular ve ekipman özeti rapora yazılır.
     * report_scope = system: sistem raporu (dolap, pano…); tesisatın genel durumunu değiştirmez, yalnızca sistemlerini.
     */
    public function saveReport(array $data, UploadedFile $file, User $user): PkInstallation
    {
        $scope = ($data['report_scope'] ?? null) === 'system' ? 'system' : null;
        $installation = !empty($data['installation_id']) ? PkInstallation::query()->findOrFail($data['installation_id']) : null;
        $locationId = $installation?->location_id ?? (int) $data['location_id'];
        $type = $this->allTypes()->firstWhere('id', (int) ($installation?->installation_type_id ?? $data['installation_type_id'] ?? 0));
        if (!$type) {
            throw ValidationException::withMessages(['installation_type_id' => 'Tesisat türünü seçin.']);
        }
        if (!$installation) {
            $this->assertPlacement($locationId, $data['location_business_entity_id'] ?? null);
            $installation = $this->existingFor($locationId, $type->id);
        }

        $analysisKey = !empty($data['analysis_id']) ? $this->analysisKey($data['analysis_id']) : null;
        $analysis = $analysisKey ? $this->cachedAnalysis($analysisKey, $locationId) : null;

        $reportNo = mb_substr(trim((string) ($data['report_no'] ?? '')) ?: trim((string) ($analysis['summary']['report_no'] ?? '')), 0, 100) ?: null;
        $hash = hash_file('sha256', $file->getRealPath());
        $systems = $this->cleanSystems((array) ($data['systems'] ?? []));
        if ($duplicate = $this->duplicateFor($reportNo, $hash, $scope, array_column($systems, 'system_id'))) {
            throw ValidationException::withMessages(['report' => $duplicate['message']]);
        }
        if ($systems === []) {
            throw ValidationException::withMessages(['systems' => 'En az bir sistem seçilmelidir.']);
        }
        // Katalog sistemi tesisatın türüne ait olmalı (ör. algılama sistemi yangın tesisatına eklenmez).
        $allowed = $type->systems->pluck('id')->all();
        if (collect($systems)->contains(fn (array $system) => $system['system_id'] && !in_array($system['system_id'], $allowed, true))) {
            throw ValidationException::withMessages(['systems' => 'Seçilen sistemlerden biri bu tesisat türüne ait değil.']);
        }
        $equipmentRows = $this->cleanEquipment((array) ($data['equipment'] ?? []), $type);

        $path = $file->store('pk-installation-reports/' . $this->tenantContext->id(), 'local');
        $linked = [];
        $report = null;
        try {
            $installation = DB::transaction(function () use ($installation, $type, $data, $scope, $locationId, $analysis, $reportNo, $hash, $systems, $equipmentRows, $file, $path, $user, &$linked, &$report) {
                $installation ??= PkInstallation::create([
                    'tenant_id' => $this->tenantContext->id(),
                    'location_id' => $locationId,
                    'location_business_entity_id' => $data['location_business_entity_id'] ?? null,
                    'installation_type_id' => $type->id,
                    'name' => trim((string) ($data['name'] ?? '')) ?: null,
                    'created_by' => $user->id,
                ]);

                // Raporun bulguları: genel bulgular (sisteme bağlı olmayan) + sistemlerin bulguları.
                $general = collect((array) ($data['findings'] ?? []))->map(fn ($finding) => trim((string) $finding))->filter();
                $findings = $general->merge(collect($systems)->flatMap(fn (array $system) => $system['findings']))->unique()->values()->all();
                $report = PkInstallationReport::create([
                    'tenant_id' => $this->tenantContext->id(),
                    'pk_installation_id' => $installation->id,
                    'control_date' => $data['control_date'],
                    'next_control_date' => $data['next_control_date'] ?? null,
                    'status' => $data['status'],
                    'source' => $analysis ? 'ai' : 'manual',
                    'report_scope' => $scope,
                    'report_file' => $path,
                    'report_file_name' => $file->getClientOriginalName(),
                    'report_no' => $reportNo,
                    'report_hash' => $hash,
                    'inspection_body' => trim((string) ($data['inspection_body'] ?? '')) ?: null,
                    'conclusion' => trim((string) ($data['conclusion'] ?? '')) ?: null,
                    'findings' => $findings ?: null,
                    'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                    'analysis' => $analysis ? Arr::only($analysis, ['model', 'analyzed_at', 'fixture', 'duration_s', 'input', 'summary', 'semantic', 'tables']) : null,
                    'created_by' => $user->id,
                ]);

                $analysisSystems = collect((array) ($analysis['summary']['systems'] ?? []));
                foreach ($systems as $input) {
                    $system = $this->resolveSystem($installation, $input);
                    // Ekipman özeti yapay zeka analizinden (aynı katalog sistemi ya da aynı rapor adı).
                    $fromAnalysis = $analysisSystems->first(fn (array $row) => (($row['system_id'] ?? null) === $input['system_id'])
                        || array_intersect((array) ($row['source_names'] ?? []), $input['source_names']));
                    PkInstallationReportSystem::create([
                        'pk_installation_report_id' => $report->id,
                        'pk_installation_system_id' => $system->id,
                        'status' => $input['status'],
                        'source_names' => $input['source_names'] ?: ($fromAnalysis['source_names'] ?? null),
                        'findings' => $input['findings'] ?: null,
                        'equipment_summary' => $fromAnalysis['equipment_summary'] ?? null ?: null,
                    ]);
                }
                // Katalogda olmayan sistemler hiçbir yere bağlanmaz: kullanıcı işaretlediyse kataloğa ekleme talebi.
                if ($requested = (array) ($data['system_requests'] ?? [])) {
                    $this->systemRequests->createForReport($report, $type->id, $requested, $user);
                }

                // Rapordaki ekipmanlar: kullanıcının seçtiği mevcut ekipman ya da yeni ekipman (Ekipmanlar'a eklenir); her
                // birine bu raporla kontrol kaydı (durum kriterlerden ya da kullanıcının seçtiği, o ekipmanın bulguları).
                foreach ($equipmentRows as $row) {
                    $equipment = $row['equipment_id']
                        ? PkEquipment::query()->where('location_id', $installation->location_id)->where('is_active', true)->find($row['equipment_id'])
                        : $this->equipment->create([
                            'location_id' => $installation->location_id,
                            'location_business_entity_id' => $installation->location_business_entity_id,
                            'equipment_type_id' => $row['equipment_type_id'],
                            'variant' => $row['variant'],
                            'code' => $row['code'],
                            'place' => $row['place'],
                            'brand' => $row['brand'],
                            'model' => $row['model'],
                            'serial_no' => $row['serial_no'],
                        ], $user);
                    if (!$equipment) {
                        throw ValidationException::withMessages(['equipment' => 'Seçilen ekipman bu lokasyonda bulunamadı.']);
                    }
                    $linked[] = $reportFile = $this->equipment->linkReportFile($path, $equipment->id);
                    $inspection = PkInspection::create([
                        'tenant_id' => $this->tenantContext->id(),
                        'pk_equipment_id' => $equipment->id,
                        'control_date' => $report->control_date,
                        'next_control_date' => $report->next_control_date,
                        'status' => $row['status'],
                        'source' => $report->source,
                        'report_file' => $reportFile,
                        'report_file_name' => $report->report_file_name,
                        'report_no' => $report->report_no,
                        'report_hash' => $report->report_hash,
                        'inspection_body' => $report->inspection_body,
                        'findings' => $row['findings'] ?: null,
                        'created_by' => $user->id,
                    ]);
                    $report->inspections()->attach($inspection->id);
                    // Teknik özellikler: katalogdakilerle eşleşenler (yeni ekipmanda geçerli, mevcutta değer ezilmez).
                    if ($row['properties']) {
                        $this->properties->recordTableRow($equipment, $inspection, $row['properties'], $report->report_no, !$row['equipment_id'], $user);
                    }
                }

                return $installation;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete([$path, ...$linked]);
            throw $exception;
        }
        if ($analysisKey) {
            Cache::forget($analysisKey);
            // Analiz geçmişi: okuma bu tesisatın kontrol raporuna dönüştü.
            rescue(fn () => $this->history->markSaved($data['analysis_id'], ['pk_installation_id' => $installation->id, 'pk_installation_report_id' => $report?->id], $path));
        }

        return $installation;
    }

    /**
     * Elektrik iç tesisatı kontrolü (elle ya da yapay zeka önerisiyle): saveReport'un Ekipmanlar'a yazmayan kopyası (yangının
     * kaydı değişmez). Rapordaki ekipmanlar (panolar, röleler, ölçüm noktaları) sistem sonucuyla birlikte saklanır;
     * Ekipmanlar'a kayıt açılmaz. "Zaten yüklenmiş" ve tesisat raporu / sistem raporu kuralları yangınla aynı.
     */
    public function saveElectricalReport(PkInstallation $installation, array $data, UploadedFile $file, User $user): PkInstallation
    {
        $type = $this->electricalTypes()->firstWhere('id', $installation->installation_type_id);
        if (!$type) {
            throw ValidationException::withMessages(['installation_id' => 'Bu kayıt bu tesisat türü için kullanılamaz.']);
        }
        $scope = ($data['report_scope'] ?? null) === 'system' ? 'system' : null;
        $analysisKey = !empty($data['analysis_id']) ? $this->analysisKey($data['analysis_id']) : null;
        $analysis = $analysisKey ? $this->cachedAnalysis($analysisKey, $installation->location_id) : null;

        $reportNo = mb_substr(trim((string) ($data['report_no'] ?? '')) ?: trim((string) ($analysis['summary']['report_no'] ?? '')), 0, 100) ?: null;
        $hash = hash_file('sha256', $file->getRealPath());
        $systems = $this->cleanSystems((array) ($data['systems'] ?? []));
        if ($duplicate = $this->duplicateFor($reportNo, $hash, $scope, array_column($systems, 'system_id'))) {
            throw ValidationException::withMessages(['report' => $duplicate['message']]);
        }
        // Tesisat raporu sistemsiz olabilir (yalnızca genel kontrol listesi: genel durum ve bulgular); sistem raporunda sistem şart.
        if ($systems === [] && $scope === 'system') {
            throw ValidationException::withMessages(['systems' => 'En az bir sistem seçilmelidir.']);
        }
        $allowed = $type->systems->pluck('id')->all();
        if (collect($systems)->contains(fn (array $system) => !in_array($system['system_id'], $allowed, true))) {
            throw ValidationException::withMessages(['systems' => 'Seçilen sistemlerden biri bu tesisat türüne ait değil.']);
        }
        // Rapordaki ekipmanlar sistem bazında (aynı sistem iki kez geldiyse birleşir).
        $components = [];
        foreach ((array) ($data['systems'] ?? []) as $input) {
            $systemId = (int) ($input['system_id'] ?? 0);
            if ($systemId && !empty($input['components'])) {
                $components[$systemId] = [...($components[$systemId] ?? []), ...$this->cleanComponents((array) $input['components'])];
            }
        }

        $path = $file->store('pk-installation-reports/' . $this->tenantContext->id(), 'local');
        $report = null;
        try {
            DB::transaction(function () use ($installation, $type, $data, $scope, $analysis, $reportNo, $hash, $systems, $components, $file, $path, $user, &$report) {
                // Raporun bulguları: genel bulgular (sisteme bağlı olmayan) + sistemlerin bulguları.
                $general = collect((array) ($data['findings'] ?? []))->map(fn ($finding) => trim((string) $finding))->filter();
                $findings = $general->merge(collect($systems)->flatMap(fn (array $system) => $system['findings']))->unique()->values()->all();
                $report = PkInstallationReport::create([
                    'tenant_id' => $this->tenantContext->id(),
                    'pk_installation_id' => $installation->id,
                    'control_date' => $data['control_date'],
                    'next_control_date' => $data['next_control_date'] ?? null,
                    'status' => $data['status'],
                    'source' => $analysis ? 'ai' : 'manual',
                    'report_scope' => $scope,
                    'report_file' => $path,
                    'report_file_name' => $file->getClientOriginalName(),
                    'report_no' => $reportNo,
                    'report_hash' => $hash,
                    'inspection_body' => trim((string) ($data['inspection_body'] ?? '')) ?: null,
                    'conclusion' => trim((string) ($data['conclusion'] ?? '')) ?: null,
                    'findings' => $findings ?: null,
                    'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                    'analysis' => $analysis ? Arr::only($analysis, ['model', 'analyzed_at', 'fixture', 'duration_s', 'input', 'summary', 'semantic', 'tables']) : null,
                    'created_by' => $user->id,
                ]);

                $analysisSystems = collect((array) ($analysis['summary']['systems'] ?? []));
                foreach ($systems as $input) {
                    $system = $this->resolveSystem($installation, $input);
                    $fromAnalysis = $analysisSystems->first(fn (array $row) => (($row['system_id'] ?? null) === $input['system_id'])
                        || array_intersect((array) ($row['source_names'] ?? []), $input['source_names']));
                    $result = PkInstallationReportSystem::create([
                        'pk_installation_report_id' => $report->id,
                        'pk_installation_system_id' => $system->id,
                        'status' => $input['status'],
                        'source_names' => $input['source_names'] ?: ($fromAnalysis['source_names'] ?? null),
                        'findings' => $input['findings'] ?: null,
                        'equipment_summary' => $fromAnalysis['equipment_summary'] ?? null ?: null,
                    ]);
                    if (!empty($components[$input['system_id']])) {
                        PkInstallationReportComponent::create(['pk_installation_report_system_id' => $result->id, 'components' => $components[$input['system_id']]]);
                    }
                }
                // Katalogda olmayan sistemler hiçbir yere bağlanmaz: kullanıcı işaretlediyse kataloğa ekleme talebi.
                if ($requested = (array) ($data['system_requests'] ?? [])) {
                    $this->systemRequests->createForReport($report, $type->id, $requested, $user);
                }
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
        if ($analysisKey) {
            Cache::forget($analysisKey);
            // Analiz geçmişi: okuma bu tesisatın kontrol raporuna dönüştü.
            rescue(fn () => $this->history->markSaved($data['analysis_id'], ['pk_installation_id' => $installation->id, 'pk_installation_report_id' => $report?->id], $path));
        }

        return $installation;
    }

    /**
     * Elektrik ailesi tesisatının sistemlerinde raporlar: sistemin geçtiği her rapor (en yeni önce) sonucu, bulguları ve
     * ölçüm sonuçlarıyla; son kontroldekiler (current) ve önceki raporlar (previous) ayrı — bkz. splitElectricalResults.
     * [{id: tesisatın sistemi, current: [...], previous: [...]}]; rapor: {report_id, control_date, report_no, scope,
     * has_report, status, findings, components}. Değerler [{label, value}] listesi (eski {başlık: değer} de çevrilir).
     */
    public function electricalSystemReports(PkInstallation $installation): array
    {
        $systems = $installation->systems()->with('results.report:id,control_date,report_no,report_scope,report_file')->get();
        $lists = $this->componentLists($systems->flatMap(fn (PkInstallationSystem $system) => $system->results));
        $entry = fn (PkInstallationReportSystem $result) => [
            'report_id' => $result->pk_installation_report_id,
            'control_date' => $result->report?->control_date?->format('Y-m-d'),
            'report_no' => $result->report?->report_no,
            'scope' => $result->report?->report_scope,
            'has_report' => (bool) $result->report?->report_file,
            'status' => $result->status,
            'findings' => (array) ($result->findings ?? []),
            'components' => collect($lists->get($result->id)?->components ?? [])
                ->map(fn (array $component) => ['code_label' => null, ...$component, 'properties' => $this->componentValues((array) ($component['properties'] ?? []))])
                ->all(),
        ];

        return $systems->map(function (PkInstallationSystem $system) use ($lists, $entry) {
            [$current, $previous] = $this->splitElectricalResults($system->results, $lists);

            return ['id' => $system->id, 'current' => $current->map($entry)->values()->all(), 'previous' => $previous->map($entry)->values()->all()];
        })->values()->all();
    }

    // Sistem sonuçlarının ölçüm sonuçları listeleri (sonuç id → liste).
    private function componentLists(Collection $results): Collection
    {
        return PkInstallationReportComponent::query()->whereIn('pk_installation_report_system_id', $results->pluck('id')->all())->get()
            ->keyBy('pk_installation_report_system_id');
    }

    /**
     * Elektrik ailesi: sistem raporları pano / bölüm bazında ayrı ayrı gelebilir (ör. her pano için ayrı rapor). Sistemin "son
     * kontrolü" (current): en son kontrol tarihinden 11 ay içindeki raporlar; ancak aynı panoları / ölçüm noktalarını
     * kapsayan daha yeni bir rapor varsa (aynı pano yeniden kontrol edilmiş) eskisi önceki raporlara düşer. Ölçüm listesi
     * olmayan sonuçlar (ör. tesisat raporunun sistem sonucu) birbirinin yerini alır. İkisi de en yeni önce.
     */
    private function splitElectricalResults(Collection $results, Collection $lists): array
    {
        $sorted = $results->sortByDesc(fn (PkInstallationReportSystem $result) => [$result->report?->control_date?->format('Y-m-d'), $result->pk_installation_report_id])->values();
        $newest = $sorted->first()?->report?->control_date;
        $keys = fn (PkInstallationReportSystem $result) => collect($lists->get($result->id)?->components ?? [])
            ->map(fn (array $component) => mb_strtolower(trim((string) ($component['equipment_name'] ?? '')) . '|' . trim((string) ($component['code'] ?? ''))))
            ->unique()->values()->all();
        $current = collect();
        $previous = collect();
        foreach ($sorted as $result) {
            $date = $result->report?->control_date;
            $mine = $keys($result);
            $replaced = $current->contains(function (PkInstallationReportSystem $newer) use ($keys, $mine) {
                $theirs = $keys($newer);

                return $mine === [] ? $theirs === [] : array_diff($mine, $theirs) === [];
            });
            $old = !$newest || !$date || $date->lt($newest->copy()->subMonths(11));
            ($old || $replaced ? $previous : $current)->push($result);
        }

        return [$current, $previous];
    }

    /**
     * Elektrik ailesi sistem özeti (presentSystem'in kopyası; yangın presentSystem'i kullanır): durum son kontroldeki
     * raporların en kötüsü — biri uygun değilse sistem uygun değil (ör. pano başına raporlarda bir pano) — bulgular
     * hepsinden, tarih en son kontrolün tarihi. Tesisatın genel durumu yine tesisat raporundan gelir.
     */
    private function presentElectricalSystem(PkInstallationSystem $system): array
    {
        [$current] = $this->splitElectricalResults($system->results, $this->componentLists($system->results));
        $statuses = $current->pluck('status')->all();

        return [
            'id' => $system->id,
            'system_id' => $system->system_id,
            'name' => $system->name,
            'slug' => $system->catalog?->slug,
            'card' => in_array($system->catalog?->slug, self::CARD_SYSTEM_SLUGS, true),
            'has_equipment' => (bool) $system->catalog?->equipmentTypes->isNotEmpty(),
            'has_results' => $system->results->isNotEmpty(),
            'status' => $current->isEmpty() ? 'rapor_yok'
                : (in_array('uygun_degil', $statuses, true) ? 'uygun_degil' : (in_array('uygun', $statuses, true) ? 'uygun' : 'belirtilmemis')),
            'last_control_date' => $current->first()?->report?->control_date?->format('Y-m-d'),
            'findings' => $current->flatMap(fn (PkInstallationReportSystem $result) => (array) ($result->findings ?? []))->unique()->values()->all(),
        ];
    }

    // Sistemin özeti: elektrik ailesinde son kontroldeki raporların en kötüsü, diğer tesisatlarda (yangın) olduğu gibi.
    private function systemSummary(PkInstallation $installation, PkInstallationSystem $system): array
    {
        return in_array($installation->type?->slug, self::ELECTRICAL_FAMILY_SLUGS, true)
            ? $this->presentElectricalSystem($system)
            : $this->presentSystem($system);
    }

    // Rapordaki ölçüm sonuçları (elektrik ailesi): no ve sütun başlığı, konum, tablo adı, sonuç, bulgular ve değerler; boş satır
    // atlanır.
    private function cleanComponents(array $rows): array
    {
        $text = fn ($value, int $max) => mb_substr(trim((string) $value), 0, $max) ?: null;

        return collect($rows)
            ->filter(fn ($row) => is_array($row))
            ->map(fn (array $row) => [
                'code' => $text($row['code'] ?? null, 255),
                'code_label' => $text($row['code_label'] ?? null, 255),
                'place' => $text($row['place'] ?? null, 255),
                'equipment_name' => $text($row['equipment_name'] ?? null, 255),
                'status' => in_array($row['status'] ?? null, ['uygun', 'uygun_degil'], true) ? $row['status'] : null,
                'findings' => collect((array) ($row['findings'] ?? []))->map(fn ($finding) => trim((string) $finding))->filter()->unique()->values()->all(),
                'properties' => $this->componentValues((array) ($row['properties'] ?? [])),
            ])
            ->filter(fn (array $row) => $row['code'] || $row['place'] || collect($row['properties'])->contains(fn (array $cell) => $cell['value'] !== null))
            ->values()
            ->all();
    }

    /**
     * Ölçüm değerleri raporun sütun sırasıyla [{label, value}] listesi olarak saklanır ({başlık: değer} nesnesinin sırasını
     * veritabanı bozar). Eski biçim ({başlık: değer}) de kabul edilir. Boş değer null kalır (sütun tabloda boş görünür).
     */
    private function componentValues(array $properties): array
    {
        $pairs = array_is_list($properties)
            ? array_map(fn ($cell) => is_array($cell) ? [$cell['label'] ?? null, $cell['value'] ?? null] : [null, null], $properties)
            : array_map(fn ($label, $value) => [$label, $value], array_keys($properties), array_values($properties));

        return collect($pairs)
            ->filter(fn (array $pair) => is_scalar($pair[0]) && trim((string) $pair[0]) !== '' && ($pair[1] === null || is_scalar($pair[1])))
            ->map(function (array $pair) {
                $value = trim((string) $pair[1]);

                return ['label' => mb_substr(trim((string) $pair[0]), 0, 255), 'value' => $value !== '' ? mb_substr($value, 0, 1000) : null];
            })
            ->unique('label')
            ->values()
            ->all();
    }

    /**
     * Rapor bekleniyor: firma kontrolü yaptı, rapor henüz gelmedi (dosyasız kayıt). Tesisatın genel durumu "rapor
     * bekleniyor" olur; sistemlerin ve ekipmanların sonuçları önceki kontrolde kalır (ekipmanlara ayrı kayıt açılmaz).
     * Rapor gelince "Kontrol Ekle" ile yüklenir; kayıt geçmişte kalır (ekipmanlardaki rapor bekleniyor gibi).
     */
    public function markPending(PkInstallation $installation, array $data, User $user): void
    {
        $last = $installation->latestReport;
        if ($last && $last->control_date && CarbonImmutable::parse($data['control_date'])->lt($last->control_date)) {
            throw ValidationException::withMessages(['control_date' => 'Son kontrol ' . $last->control_date->format('d.m.Y') . ' tarihli; kontrol tarihi bundan önce olamaz.']);
        }
        PkInstallationReport::create([
            'tenant_id' => $this->tenantContext->id(),
            'pk_installation_id' => $installation->id,
            'control_date' => $data['control_date'],
            'status' => 'rapor_bekleniyor',
            'source' => 'manual',
            'inspection_body' => trim((string) ($data['inspection_body'] ?? '')) ?: null,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            'created_by' => $user->id,
        ]);
    }

    // Rapor silinir: dosyası, sistem sonuçları ve ondan ekipmanlara yazılan kontrol kayıtları da; sistemler ve ekipmanlar
    // kalır (sonuçları bir önceki kontrole döner).
    public function deleteReport(PkInstallation $installation, PkInstallationReport $report): void
    {
        abort_if($report->pk_installation_id !== $installation->id, 404);
        $file = $report->report_file;
        $this->systemRequests->forgetPending([$report->id]);
        $this->forgetInspections($report);
        $report->delete();
        if ($file) {
            Storage::disk('local')->delete($file);
        }
    }

    public function delete(PkInstallation $installation): void
    {
        $reports = $installation->reports()->get();
        $this->systemRequests->forgetPending($reports->pluck('id')->all());
        $reports->each(fn (PkInstallationReport $report) => $this->forgetInspections($report));
        $installation->delete();
        Storage::disk('local')->delete($reports->pluck('report_file')->filter()->all());
    }

    // Rapordan ekipmanlara yazılan kontrol kayıtları Ekipmanlar'ın kendi silme işlemiyle silinir (dosyası kendi bağlantısı).
    private function forgetInspections(PkInstallationReport $report): void
    {
        foreach ($report->inspections()->with('equipment')->get() as $inspection) {
            if ($inspection->equipment) {
                $this->equipment->deleteInspection($inspection->equipment, $inspection);
            } else {
                $inspection->delete();
            }
        }
    }

    /**
     * Rapordaki ekipman satırları (kullanıcının onayladığı): tür satırın sisteminin ekipman türlerinden olmalı; aynı
     * mevcut ekipman iki satırda seçilemez.
     */
    private function cleanEquipment(array $rows, PeriodicEquipmentType $type): array
    {
        $systemTypes = $type->systems->mapWithKeys(fn (PeriodicInstallationSystem $system) => [$system->id => $system->equipmentTypes->pluck('id')->all()]);
        $text = fn ($value, int $max) => mb_substr(trim((string) $value), 0, $max) ?: null;
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $typeId = (int) ($row['equipment_type_id'] ?? 0);
            if (!in_array($typeId, $systemTypes->get((int) ($row['system_id'] ?? 0), []), true)) {
                throw ValidationException::withMessages(['equipment' => 'Ekipmanlardan birinin türü sistemine ait değil.']);
            }
            $equipmentId = !empty($row['equipment_id']) ? (int) $row['equipment_id'] : null;
            if ($equipmentId && isset($seen[$equipmentId])) {
                throw ValidationException::withMessages(['equipment' => 'Aynı ekipman iki satırda seçilmiş.']);
            }
            $seen[$equipmentId ?? 0] = true;
            $out[] = [
                'equipment_type_id' => $typeId,
                'equipment_id' => $equipmentId,
                'status' => in_array($row['status'] ?? null, ['uygun', 'uygun_degil'], true) ? $row['status'] : 'belirtilmemis',
                'code' => $text($row['code'] ?? null, 100),
                'place' => $text($row['place'] ?? null, 255),
                'brand' => $text($row['brand'] ?? null, 100),
                'model' => $text($row['model'] ?? null, 100),
                'serial_no' => $text($row['serial_no'] ?? null, 100),
                // Etiket (türün katalogdaki etiketlerinden; geçersizse ekipman eklenirken boşalır).
                'variant' => $text($row['variant'] ?? null, 50),
                'properties' => collect((array) ($row['properties'] ?? []))
                    ->filter(fn ($value, $label) => is_scalar($value) && trim((string) $value) !== '' && trim((string) $label) !== '')
                    ->mapWithKeys(fn ($value, $label) => [mb_substr(trim((string) $label), 0, 255) => mb_substr(trim((string) $value), 0, 1000)])
                    ->all(),
                'findings' => collect((array) ($row['findings'] ?? []))->map(fn ($finding) => trim((string) $finding))->filter()->unique()->values()->all(),
            ];
        }

        return $out;
    }

    // Rapor dosyası (private disk): [mutlak yol, indirme adı].
    /**
     * Kontrol geçmişi (bütün tesisatlar): raporla saklanan yapay zeka okumasının ham bilgileri, Ekipmanlar'daki yapay zeka
     * analiziyle aynı biçimde — kuruluş ve uzmanlar, rapor ve tesis bilgileri, sonuç ve kanaat, bölümler, bulgular. Yapay
     * zekayla okunmamış raporda null. Yalnızca okur.
     */
    public function reportAnalysis(PkInstallation $installation, PkInstallationReport $report): ?array
    {
        abort_if($report->pk_installation_id !== $installation->id, 404);
        $analysis = (array) ($report->analysis ?? []);
        if ($analysis === []) {
            return null;
        }
        $data = (array) ($analysis['semantic']['extracted_data'] ?? []);
        $values = fn (array $rows) => collect($rows)
            ->filter(fn ($row) => is_array($row) && filled($row['value'] ?? null))
            ->map(fn (array $row) => ['key' => $row['key'] ?? null, 'value' => $row['value']])
            ->values()
            ->all();

        return [
            'model' => $analysis['model'] ?? null,
            'analyzed_at' => $analysis['analyzed_at'] ?? null,
            'fixture' => $analysis['fixture'] ?? null,
            'report_information' => $values((array) ($data['report_information'] ?? [])),
            'facility_information' => $values((array) ($data['facility_information'] ?? [])),
            'overall_result' => $data['overall_result'] ?? null,
            'inspection_body' => $data['inspection_body'] ?? null,
            'findings' => collect((array) ($data['findings'] ?? []))
                ->filter(fn ($finding) => is_array($finding))
                ->map(fn (array $finding) => Arr::only($finding, ['description', 'system_name', 'severity']))
                ->values()
                ->all(),
            'systems' => collect((array) ($analysis['semantic']['template']['fire_systems']['systems'] ?? []))
                ->filter(fn ($system) => is_array($system))
                ->map(fn (array $system) => ['name' => $system['system_name'] ?? null, 'status' => $system['verdict']['status'] ?? null])
                ->values()
                ->all(),
        ];
    }

    public function reportFile(PkInstallation $installation, PkInstallationReport $report): array
    {
        if ($report->pk_installation_id !== $installation->id || !$report->report_file || !Storage::disk('local')->exists($report->report_file)) {
            abort(404, 'Rapor dosyası bulunamadı.');
        }

        return [Storage::disk('local')->path($report->report_file), $report->report_file_name ?: basename($report->report_file)];
    }

    // Aynı rapor ikinci kez yüklenemez: rapor no (raporda yazan) ya da aynı dosya.
    public function duplicateReport(?string $reportNo, ?string $hash): ?array
    {
        $reportNo = trim((string) $reportNo);
        if ($reportNo === '' && blank($hash)) {
            return null;
        }
        $existing = PkInstallationReport::query()
            ->where(fn ($query) => $query
                ->when($reportNo !== '', fn ($q) => $q->orWhere('report_no', $reportNo))
                ->when(filled($hash), fn ($q) => $q->orWhere('report_hash', $hash)))
            ->with('installation.type')
            ->orderBy('id')
            ->first();
        if (!$existing) {
            return null;
        }
        $by = $reportNo !== '' && $existing->report_no === $reportNo ? "rapor no {$reportNo}" : 'aynı dosya';

        return [
            'report_id' => $existing->id,
            'installation_id' => $existing->pk_installation_id,
            'message' => "Bu rapor zaten yüklenmiş ({$by}): " . ($existing->installation?->name ?: $existing->installation?->type?->name ?: 'tesisat')
                . ', kontrol tarihi ' . ($existing->control_date?->format('d.m.Y') ?? '—') . '.',
        ];
    }

    /**
     * "Zaten yüklenmiş" kuralı (tesisat raporu / sistem raporu): aynı dosya her zaman; tesisat raporunda aynı rapor no'lu
     * başka bir tesisat raporu; sistem raporunda aynı rapor no ile aynı sistemin sistem raporu. Rapor no aynı ama sistem
     * farklıysa (ya da no bir tesisat raporununkiyle aynıysa) ayrı rapordur, kabul edilir. $systemIds: katalog sistemleri.
     */
    private function duplicateFor(?string $reportNo, ?string $hash, ?string $scope, array $systemIds = []): ?array
    {
        $reportNo = trim((string) $reportNo);
        $query = fn () => PkInstallationReport::query()->with(['installation.type', 'systems.system:id,system_id,name'])->orderBy('id');
        if (filled($hash) && ($existing = $query()->where('report_hash', $hash)->first())) {
            return $this->duplicateMessage($existing, 'aynı dosya');
        }
        if ($reportNo === '') {
            return null;
        }
        if ($scope !== 'system') {
            $existing = $query()->where('report_no', $reportNo)->whereNull('report_scope')->first();

            return $existing ? $this->duplicateMessage($existing, "rapor no {$reportNo}") : null;
        }
        $systemIds = array_map('intval', $systemIds);
        $existing = $systemIds
            ? $query()->where('report_no', $reportNo)->where('report_scope', 'system')
                ->whereHas('systems.system', fn ($system) => $system->whereIn('system_id', $systemIds))->first()
            : null;
        if (!$existing) {
            return null;
        }
        $names = $existing->systems->map(fn (PkInstallationReportSystem $result) => $result->system)
            ->filter(fn (?PkInstallationSystem $system) => in_array((int) $system?->system_id, $systemIds, true))->pluck('name')->implode(', ');

        return $this->duplicateMessage($existing, "rapor no {$reportNo} · {$names}", 'Bu sistem raporu');
    }

    private function duplicateMessage(PkInstallationReport $existing, string $by, string $what = 'Bu rapor'): array
    {
        return [
            'report_id' => $existing->id,
            'installation_id' => $existing->pk_installation_id,
            'message' => "{$what} zaten yüklenmiş ({$by}): " . ($existing->installation?->name ?: $existing->installation?->type?->name ?: 'tesisat')
                . ', kontrol tarihi ' . ($existing->control_date?->format('d.m.Y') ?? '—') . '.',
        ];
    }

    // Yapay zeka okumasından sonra: aynı rapor no ile yüklenmiş raporlar (türü ve katalog sistemleriyle); ekran seçilen türe
    // göre "zaten yüklenmiş" uyarısını önceden gösterir.
    private function reportNoMatches(?string $reportNo): array
    {
        $reportNo = trim((string) $reportNo);
        if ($reportNo === '') {
            return [];
        }

        return PkInstallationReport::query()->where('report_no', $reportNo)->where('status', '!=', 'rapor_bekleniyor')
            ->with(['installation.type', 'systems.system:id,system_id,name'])->orderBy('id')->get()
            ->map(fn (PkInstallationReport $report) => [
                'scope' => $report->report_scope,
                'system_ids' => $report->systems->map(fn (PkInstallationReportSystem $result) => $result->system?->system_id)->filter()->values()->all(),
                'system_names' => $report->systems->map(fn (PkInstallationReportSystem $result) => $result->system?->name)->filter()->values()->all(),
                'installation' => $report->installation?->name ?: $report->installation?->type?->name,
                'control_date' => $report->control_date?->format('Y-m-d'),
            ])
            ->values()
            ->all();
    }

    private function query()
    {
        return PkInstallation::query()->with([
            'type:id,slug,name,default_period_months',
            'location:id,name',
            'workplace.businessEntity.company',
            'latestReport',
            'systems.catalog:id,slug,sort_order',
            'systems.catalog.equipmentTypes',
            'systems.results.report:id,control_date',
        ])->withCount('reports');
    }

    /**
     * Tesisatın özeti. Genel durum ve tarihler son kontrolün genel sonucundan (sistemlerden hesaplanmaz); hiç kontrol
     * yoksa rapor yok.
     */
    private function present(PkInstallation $installation): array
    {
        $report = $installation->latestReport;
        $statuses = $installation->systems->map(fn (PkInstallationSystem $system) => $this->systemSummary($installation, $system)['status']);
        $business = $installation->workplace?->businessEntity;

        $summary = [
            'id' => $installation->id,
            'name' => $installation->name ?: $installation->type?->name,
            'type' => $installation->type ? ['id' => $installation->type->id, 'slug' => $installation->type->slug, 'name' => $installation->type->name] : null,
            'location' => $installation->location ? ['id' => $installation->location->id, 'name' => $installation->location->name] : null,
            'workplace' => $installation->workplace ? ['id' => $installation->workplace->id, 'company_name' => $business?->company?->name ?? $business?->name] : null,
            'status' => $report?->status ?? 'rapor_yok',
            'last_control_date' => $report?->control_date?->format('Y-m-d'),
            'next_control_date' => $report?->next_control_date?->format('Y-m-d'),
            'days_left' => $this->daysLeft($report?->next_control_date),
            'inspection_body' => $report?->inspection_body,
            'systems_count' => $statuses->count(),
            'nonconforming_systems' => $statuses->filter(fn ($status) => $status === 'uygun_degil')->count(),
            'reports_count' => $installation->reports_count ?? 0,
        ];

        // Trafo merkezi: genel durum ve tarihler hepsinin toplamından (transformerOverview); diğer tesisatlarda aynı.
        return $installation->type?->slug === self::TRANSFORMER_TYPE_SLUG ? array_replace($summary, $this->transformerOverview($installation, $report)) : $summary;
    }

    /**
     * Trafo merkezi: rapor her ekipman (trafo, kesici, hücre) için ayrı gelebilir (ZPKK05), genel rapor olmayabilir. Genel durum
     * hepsinin toplamı: son kontroldeki raporlardan (sistemlerin son kontrolleri — bkz. splitElectricalResults — ve en yeni
     * rapordan 11 ay içindeyse son tesisat raporu) biri uygun değilse uygun değil. Son kontrol tarihi en yeni rapordan, sonraki
     * kontrol süresi en erken dolandan, kuruluş en yeni rapordan. Son kayıt "rapor bekleniyor" ise değişiklik yok.
     */
    private function transformerOverview(PkInstallation $installation, ?PkInstallationReport $report): array
    {
        $ids = $installation->systems->flatMap(function (PkInstallationSystem $system) {
            [$current] = $this->splitElectricalResults($system->results, $this->componentLists($system->results));

            return $current->pluck('pk_installation_report_id');
        })->unique()->values()->all();
        $reports = $ids ? PkInstallationReport::query()->whereIn('id', $ids)->get(['id', 'control_date', 'next_control_date', 'status', 'inspection_body']) : collect();
        $newest = $reports->max(fn (PkInstallationReport $item) => $item->control_date?->format('Y-m-d'));
        if ($report?->status === 'rapor_bekleniyor' && (!$newest || $report->control_date?->format('Y-m-d') >= $newest)) {
            return [];
        }
        if ($report && $report->status !== 'rapor_bekleniyor' && !$reports->contains('id', $report->id)
            && (!$newest || !$report->control_date || $report->control_date->gte(CarbonImmutable::parse($newest)->subMonths(11)))) {
            $reports->push($report);
        }
        if ($reports->isEmpty()) {
            return [];
        }
        $statuses = $reports->pluck('status')->all();
        $latest = $reports->sortByDesc(fn (PkInstallationReport $item) => [$item->control_date?->format('Y-m-d'), $item->id])->first();
        $next = $reports->pluck('next_control_date')->filter()->sort()->first();

        return [
            'status' => in_array('uygun_degil', $statuses, true) ? 'uygun_degil' : (in_array('uygun', $statuses, true) ? 'uygun' : 'belirtilmemis'),
            'last_control_date' => $latest->control_date?->format('Y-m-d'),
            'next_control_date' => $next?->format('Y-m-d'),
            'days_left' => $this->daysLeft($next),
            'inspection_body' => $latest->inspection_body,
        ];
    }

    // Sistemin güncel durumu ve bulguları: sistemin geçtiği en son kontrol (kontrol tarihi, sonra id).
    private function presentSystem(PkInstallationSystem $system): array
    {
        $latest = $system->results->sortByDesc(fn (PkInstallationReportSystem $result) => [$result->report?->control_date?->format('Y-m-d'), $result->pk_installation_report_id])->first();

        return [
            'id' => $system->id,
            'system_id' => $system->system_id,
            'name' => $system->name,
            'slug' => $system->catalog?->slug,
            'card' => in_array($system->catalog?->slug, self::CARD_SYSTEM_SLUGS, true),
            'has_equipment' => (bool) $system->catalog?->equipmentTypes->isNotEmpty(),
            // Kontrol kaydında geçen sistem düzenlemede çıkarılamaz.
            'has_results' => $system->results->isNotEmpty(),
            'status' => $latest ? ($latest->status ?? 'belirtilmemis') : 'rapor_yok',
            'last_control_date' => $latest?->report?->control_date?->format('Y-m-d'),
            'findings' => (array) ($latest?->findings ?? []),
        ];
    }

    private function presentReport(PkInstallationReport $report): array
    {
        return [
            'id' => $report->id,
            'control_date' => $report->control_date?->format('Y-m-d'),
            'next_control_date' => $report->next_control_date?->format('Y-m-d'),
            'status' => $report->status,
            'source' => $report->source,
            // Boş = tesisat raporu; 'system' = sistem raporu (tesisatın genel durumunu değiştirmez).
            'scope' => $report->report_scope,
            'report_no' => $report->report_no,
            'report_file_name' => $report->report_file_name,
            'has_report' => (bool) $report->report_file,
            'inspection_body' => $report->inspection_body,
            'notes' => $report->notes,
            'conclusion' => $report->conclusion,
            'findings' => $report->findings ?? [],
            'systems' => $report->systems->map(fn (PkInstallationReportSystem $result) => ['name' => $result->system?->name, 'status' => $result->status])->values(),
            // Bu rapordan kontrol kaydı yazılan ekipman sayısı.
            'equipment_count' => $report->inspections_count ?? 0,
            'analysis' => $report->analysis ? Arr::only($report->analysis, ['model', 'analyzed_at', 'fixture', 'input']) : null,
            'created_by' => $report->creator?->name,
            'created_at' => $report->created_at,
        ];
    }

    // Düzenlemede sistem listesi: yeniler eklenir, çıkarılanlar silinir. Kontrol kaydında geçen sistem çıkarılamaz
    // (kontrol geçmişindeki sonucu da silinirdi).
    private function syncSystems(PkInstallation $installation, array $systemIds): void
    {
        $current = $installation->systems()->withCount('results')->get();
        $type = $this->allTypes()->firstWhere('id', $installation->installation_type_id);
        $systemIds = $this->allowedSystems($type, $systemIds, $current->pluck('system_id')->filter()->all());
        $removed = $current->reject(fn (PkInstallationSystem $system) => in_array($system->system_id, $systemIds, true));
        if ($locked = $removed->first(fn (PkInstallationSystem $system) => $system->results_count > 0)) {
            throw ValidationException::withMessages(['system_ids' => "{$locked->name} kontrol kayıtlarında geçiyor; çıkarılamaz."]);
        }
        PkInstallationSystem::query()->whereKey($removed->pluck('id'))->delete();
        foreach ($systemIds as $systemId) {
            $this->resolveSystem($installation, ['system_id' => $systemId]);
        }
    }

    // Seçilen sistemler tesisat türünün katalogundan olmalı ($keep: tesisatta zaten olan, katalogdan kalkmış sistemler).
    private function allowedSystems(?PeriodicEquipmentType $type, array $systemIds, array $keep = []): array
    {
        $systemIds = array_values(array_unique(array_map('intval', array_filter($systemIds))));
        $allowed = [...($type?->systems->pluck('id')->all() ?? []), ...$keep];
        if (array_diff($systemIds, $allowed)) {
            throw ValidationException::withMessages(['system_ids' => 'Seçilen sistemlerden biri bu tesisat türüne ait değil.']);
        }

        return $systemIds;
    }

    // Tesisatın sistemi: katalog sistemi (ön tanımlı); tesisatta henüz yoksa eklenir.
    private function resolveSystem(PkInstallation $installation, array $input): PkInstallationSystem
    {
        $catalog = PeriodicInstallationSystem::query()->findOrFail($input['system_id']);

        return $installation->systems()->where('system_id', $catalog->id)->first() ?? PkInstallationSystem::create([
            'tenant_id' => $this->tenantContext->id(),
            'pk_installation_id' => $installation->id,
            'system_id' => $catalog->id,
            'name' => $catalog->name,
            'sort_order' => $catalog->sort_order,
        ]);
    }

    // Formdaki sistemler (katalogdan); aynı sistem iki kez gelirse birleşir (en kötü sonuç).
    private function cleanSystems(array $systems): array
    {
        $out = [];
        foreach ($systems as $system) {
            $systemId = !empty($system['system_id']) ? (int) $system['system_id'] : null;
            if (!$systemId) {
                continue;
            }
            $key = "c{$systemId}";
            $status = in_array($system['status'] ?? null, ['uygun', 'uygun_degil'], true) ? $system['status'] : null;
            $findings = collect((array) ($system['findings'] ?? []))->map(fn ($finding) => trim((string) $finding))->filter()->values()->all();
            $sources = collect((array) ($system['source_names'] ?? []))->map(fn ($name) => trim((string) $name))->filter()->values()->all();
            if (isset($out[$key])) {
                $out[$key]['status'] = in_array('uygun_degil', [$out[$key]['status'], $status], true) ? 'uygun_degil' : ($out[$key]['status'] ?? $status);
                $out[$key]['findings'] = array_values(array_unique([...$out[$key]['findings'], ...$findings]));
                $out[$key]['source_names'] = array_values(array_unique([...$out[$key]['source_names'], ...$sources]));
                continue;
            }
            $out[$key] = ['system_id' => $systemId, 'status' => $status, 'findings' => $findings, 'source_names' => $sources];
        }

        return array_values($out);
    }

    private function cachedAnalysis(string $key, int $locationId): ?array
    {
        $analysis = Cache::get($key);
        if (!is_array($analysis) || ($analysis['tenant_id'] ?? null) !== $this->tenantContext->id() || (int) ($analysis['location_id'] ?? 0) !== $locationId) {
            throw ValidationException::withMessages(['analysis_id' => 'Yapay zeka analizinin süresi doldu; raporu yeniden analiz edin ya da elle doldurun.']);
        }

        return $analysis;
    }

    private function analysisKey(string $id): string
    {
        return 'pk-installation-analysis:' . $id;
    }

    private function daysLeft(mixed $date): ?int
    {
        return $date ? (int) CarbonImmutable::today()->diffInDays(CarbonImmutable::parse($date), false) : null;
    }

    // Lokasyon tenant'a ait olmalı; işyeri seçildiyse o lokasyona ait olmalı.
    private function assertPlacement(int $locationId, ?int $workplaceId): void
    {
        if (!Location::query()->whereKey($locationId)->exists()) {
            throw ValidationException::withMessages(['location_id' => 'Lokasyon bulunamadı.']);
        }
        if ($workplaceId !== null && !LocationBusinessEntity::query()->whereKey($workplaceId)->where('location_id', $locationId)->exists()) {
            throw ValidationException::withMessages(['location_business_entity_id' => 'İşyeri bu lokasyona ait değil.']);
        }
    }
}
