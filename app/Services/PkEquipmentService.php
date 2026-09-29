<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Location;
use App\Models\LocationBusinessEntity;
use App\Models\PeriodicEquipmentSpecRequest;
use App\Models\PeriodicEquipmentType;
use App\Models\PkEquipment;
use App\Models\PkEquipmentPropertyValue;
use App\Models\PkInspection;
use App\Models\PkInstallation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// pktakip ekipmanları: listeleme (lokasyon / işyeri context'ine göre), elle ekleme/düzenleme ve kontrol kayıtları.
class PkEquipmentService
{
    // Pasife alma nedenleri: hurdaya ayrıldı, satıldı, başka yere taşındı, diğer.
    public const DEACTIVATION_REASONS = ['hurda', 'satildi', 'tasindi', 'diger'];

    public function __construct(
        private TenantContext $tenantContext,
        private PkEquipmentPropertyService $properties,
        private PkReportAnalysisHistory $history
    ) {
    }

    /**
     * Lokasyon context'i: lokasyondaki tüm ekipmanlar. İşyeri context'i: o işyerinin ekipmanları + lokasyon geneli.
     */
    public function list(array $filters): Collection
    {
        $items = PkEquipment::query()
            ->with(['type.category', 'location:id,name', 'workplace.businessEntity.company', 'latestInspection'])
            ->withCount('inspections')
            ->when($filters['location_id'] ?? null, fn ($query, $locationId) => $query->where('location_id', $locationId))
            // Genel bakış / durum raporu: birden çok lokasyon.
            ->when(array_key_exists('location_ids', $filters), fn ($query) => $query->whereIn('location_id', (array) $filters['location_ids']))
            ->when($filters['workplace_id'] ?? null, fn ($query, $workplaceId) => $query->where(
                fn ($inner) => $inner->whereNull('location_business_entity_id')->orWhere('location_business_entity_id', $workplaceId)
            ))
            ->when($filters['category'] ?? null, fn ($query, $slug) => $query->whereHas('type.category', fn ($q) => $q->where('slug', $slug)))
            ->when($filters['type_id'] ?? null, fn ($query, $typeId) => $query->where('equipment_type_id', $typeId))
            // Tür içinde eklenme sırası (numaraya göre değil: tüp / dolap numaraları yere göre yeniden başlar).
            ->orderBy('equipment_type_id')
            ->orderBy('id')
            ->get();
        $installationOf = $this->installationResolver($items);

        return $items
            ->map(fn (PkEquipment $equipment) => $this->present($equipment) + ['installation' => $installationOf($equipment)])
            ->values();
    }

    public function show(PkEquipment $equipment): array
    {
        $equipment->load(['type.category', 'location:id,name', 'workplace.businessEntity.company', 'latestInspection'])->loadCount('inspections');

        return $this->present($equipment) + [
            'installation' => $this->installationOf($equipment),
            // Rapordan okunan teknik özellikler: güncel değer + geçmiş (elle girişte sabit alanlar yeterli).
            'properties' => $this->properties->present($equipment),
            // Katalog dışı özellikler için yetkiliye gönderilmiş, onay bekleyen talepler.
            'pending_specs' => $this->properties->pendingRequests($equipment),
            'inspections' => $equipment->inspections()
                ->with('creator:id,name')
                ->withExists('installationReports')
                ->withExists('bulkReports')
                ->orderByDesc('control_date')->orderByDesc('id')
                ->get()
                ->map(fn (PkInspection $inspection) => $this->presentInspection($inspection, $equipment))
                ->values(),
        ];
    }

    public function create(array $data, User $user): PkEquipment
    {
        $this->assertPlacement($data['location_id'], $data['location_business_entity_id'] ?? null);

        return PkEquipment::create([
            'tenant_id' => $this->tenantContext->id(),
            'location_id' => $data['location_id'],
            'location_business_entity_id' => $data['location_business_entity_id'] ?? null,
            'equipment_type_id' => $data['equipment_type_id'],
            'variant' => $this->validVariant($data['equipment_type_id'], $data['variant'] ?? null),
            'name' => $data['name'] ?? null,
            'code' => $data['code'] ?? null,
            'serial_no' => $data['serial_no'] ?? null,
            'brand' => $data['brand'] ?? null,
            'model' => $data['model'] ?? null,
            'place' => $data['place'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $user->id,
        ]);
    }

    public function update(PkEquipment $equipment, array $data): PkEquipment
    {
        $this->assertPlacement($data['location_id'] ?? $equipment->location_id, array_key_exists('location_business_entity_id', $data) ? $data['location_business_entity_id'] : $equipment->location_business_entity_id);
        // Etiket türün seçeneklerinden biri olmalı; tür değişip etiket verilmediyse yeni türde geçerli değilse boşalır.
        $typeId = $data['equipment_type_id'] ?? $equipment->equipment_type_id;
        if (array_key_exists('variant', $data) || $typeId !== $equipment->equipment_type_id) {
            $variant = array_key_exists('variant', $data) ? $data['variant'] : $equipment->variant;
            $data['variant'] = array_key_exists('variant', $data)
                ? $this->validVariant($typeId, $variant)
                : (in_array($variant, $this->variantsOf($typeId), true) ? $variant : null);
        }
        $equipment->update($data);

        return $equipment;
    }

    // Profil fotoğrafı: public diske kaydedilir, eskisi silinir.
    public function setPhoto(PkEquipment $equipment, ?UploadedFile $photo): PkEquipment
    {
        $old = $equipment->photo_path;
        $equipment->update(['photo_path' => $photo?->store("pk-equipment/{$equipment->id}", 'public')]);
        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return $equipment;
    }

    public function delete(PkEquipment $equipment): void
    {
        $photo = $equipment->photo_path;
        $equipment->delete();
        if ($photo) {
            Storage::disk('public')->delete($photo);
        }
        Storage::disk('local')->deleteDirectory($this->reportDir($equipment->id));
    }

    /**
     * Pasife alma (tek ya da toplu): ekipman ve geçmişi kalır (silmeden farkı). Pasif ekipmana kontrol eklenmez; kalan
     * gün hesabına, tesisat listesine ve tesisat raporu eşleştirmesine girmez. Yalnızca tenant'ın ekipmanları (TenantScope).
     */
    public function deactivate(array $ids, array $data): int
    {
        return PkEquipment::query()->whereIn('id', $ids)->where('is_active', true)->update([
            'is_active' => false,
            'deactivated_at' => $data['deactivated_at'] ?? now()->toDateString(),
            'deactivation_reason' => $data['reason'],
            'deactivation_note' => trim((string) ($data['note'] ?? '')) ?: null,
        ]);
    }

    // Aktife alma: pasif bilgisi (tarih, neden, not) silinir.
    public function activate(array $ids): int
    {
        return PkEquipment::query()->whereIn('id', $ids)->where('is_active', false)->update([
            'is_active' => true,
            'deactivated_at' => null,
            'deactivation_reason' => null,
            'deactivation_note' => null,
        ]);
    }

    // Toplu silme: yalnızca tenant'a ait ekipmanlar bulunur (TenantScope); fotoğraflar da silinir.
    public function deleteMany(array $ids): int
    {
        $items = PkEquipment::query()->whereIn('id', $ids)->get();
        DB::transaction(fn () => $items->each(fn (PkEquipment $equipment) => $equipment->delete()));
        $items->pluck('photo_path')->filter()->each(fn (string $path) => Storage::disk('public')->delete($path));
        $items->each(fn (PkEquipment $equipment) => Storage::disk('local')->deleteDirectory($this->reportDir($equipment->id)));

        return $items->count();
    }

    /**
     * Uygun / uygun değil: periyodik kontrol raporu zorunlu (dijital arşiv), değerler elle ya da yapay zeka ile doldurulur.
     * Rapor bekleniyor: firma kontrolü yaptı, rapor henüz gelmedi; dosyasız girilir.
     */
    public function addInspection(PkEquipment $equipment, array $data, User $user, ?UploadedFile $report = null): PkInspection
    {
        // Yeni oluşturulan ekipmanda alan yüklenmemiş olabilir (varsayılan aktif): yalnızca açıkça pasifse engellenir.
        if ($equipment->is_active === false) {
            throw ValidationException::withMessages(['status' => 'Bu ekipman pasif; kontrol eklemek için önce aktife alın.']);
        }
        // Tesisata bağlı ekipmanın kontrolü tesisat raporundan gelir: rapor ve rapor bekleniyor tesisat düzeyinde girilir.
        if ($installation = $this->installationOf($equipment)) {
            throw ValidationException::withMessages(['status' => "Bu ekipmanın kontrolü {$installation['name']} raporundan gelir; raporu Tesisatlar'dan ekleyin."]);
        }
        $pending = $data['status'] === 'rapor_bekleniyor';
        if (!$pending && !$report) {
            throw ValidationException::withMessages(['report' => 'Periyodik kontrol raporu yüklenmelidir.']);
        }
        // Rapor bekleniyor'da da zorunlu: firmanın kontrol ettiği gün; en son kontrol bu tarihe göre bulunur.
        if (empty($data['control_date'])) {
            throw ValidationException::withMessages(['control_date' => 'Kontrol tarihi zorunludur.']);
        }

        $analysisKey = !$pending && ($data['source'] ?? null) === 'ai' && !empty($data['analysis_id']) ? $this->analysisKey($data['analysis_id']) : null;
        $analysis = $analysisKey ? $this->cachedAnalysis($analysisKey, $equipment) : null;
        if ($analysis && !empty($data['mismatch_confirmed'])) {
            // Kullanıcı "rapor bu ekipmana ait görünmüyor" uyarısına rağmen devam etti.
            $analysis['summary']['match']['confirmed_by_user'] = true;
        }

        // Aynı rapor ikinci kez yüklenemez: rapor no (raporda yazan, benzersiz) ya da aynı dosya.
        $data['report_no'] = $pending ? null : (mb_substr(trim((string) ($data['report_no'] ?? '')) ?: trim((string) ($analysis['summary']['report_no'] ?? '')), 0, 100) ?: null);
        $data['report_hash'] = $pending ? null : hash_file('sha256', $report->getRealPath());
        if ($duplicate = $this->duplicateReport($data['report_no'], $data['report_hash'])) {
            throw ValidationException::withMessages(['report' => $duplicate['message']]);
        }

        $path = $pending ? null : $report->store($this->reportDir($equipment->id), 'local');

        // Kontrol kaydı + ekipman / özellik / katalog değişiklikleri tek işlem: biri başarısız olursa hiçbiri kalmaz.
        try {
            $inspection = DB::transaction(fn () => $this->storeInspection($equipment, $data, $user, $report, $pending, $path, $analysis));
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
        if ($analysisKey) {
            Cache::forget($analysisKey);
            // Analiz geçmişi: okuma bu ekipmanın kontrol kaydına dönüştü.
            rescue(fn () => $this->history->markSaved($data['analysis_id'], ['pk_equipment_id' => $equipment->id, 'pk_inspection_id' => $inspection->id], $path));
        }

        return $inspection;
    }

    private function storeInspection(PkEquipment $equipment, array $data, User $user, ?UploadedFile $report, bool $pending, ?string $path, ?array $analysis): PkInspection
    {
        $inspection = PkInspection::create([
            'tenant_id' => $this->tenantContext->id(),
            'pk_equipment_id' => $equipment->id,
            'control_date' => $data['control_date'] ?? null,
            'next_control_date' => $pending ? null : ($data['next_control_date'] ?? null),
            'status' => $data['status'],
            'source' => !$pending && ($data['source'] ?? null) === 'ai' ? 'ai' : 'manual',
            'report_file' => $path,
            'report_file_name' => $pending ? null : $report->getClientOriginalName(),
            'report_no' => $data['report_no'],
            'report_hash' => $data['report_hash'],
            'note' => $data['note'] ?? null,
            // Periyodik kontrolü yapan kuruluş (elle ya da yapay zekanın bulduğu, kullanıcının onayladığı).
            'inspection_body' => trim((string) ($data['inspection_body'] ?? '')) ?: null,
            // Sonuç ve kanaat + madde madde bulgular: yalnızca raporlu kontrolde (rapor bekleniyor'da yok).
            'conclusion' => $pending ? null : (trim((string) ($data['conclusion'] ?? '')) ?: null),
            'findings' => $pending ? null : ($this->cleanFindings($data['findings'] ?? []) ?: null),
            'analysis' => $analysis,
            'created_by' => $user->id,
        ]);
        // Yapay zeka rapordan etiketi anladıysa (örn. Tahrik Türü: ELEKTRİKLİ): ekipmanda etiket yoksa ve kullanıcı onayladıysa eklenir.
        $reportVariant = $analysis['summary']['match']['variant'] ?? null;
        if ($reportVariant && !empty($data['apply_variant']) && !$equipment->variant && in_array($reportVariant, $this->variantsOf($equipment->equipment_type_id), true)) {
            $equipment->update(['variant' => $reportVariant]);
        }
        // Ekipman no: ekipmanda boşsa ve kullanıcı onayladıysa rapordaki no yazılır (no tekrar edebilir, benzersiz değildir).
        $reportCode = trim((string) ($analysis['summary']['match']['fields']['code'] ?? ''));
        if ($reportCode !== '' && !empty($data['apply_code']) && blank($equipment->code)) {
            $equipment->update(['code' => mb_substr($reportCode, 0, 100)]);
        }
        if ($analysis) {
            $this->properties->recordReport(
                $equipment,
                $inspection,
                $analysis,
                (array) ($data['property_decisions'] ?? []),
                (array) ($data['unmapped_decisions'] ?? []),
                $user
            );
        }

        return $inspection;
    }

    /**
     * Yapay zeka analizini kayda kadar saklar (24 saat); kayıtta analysis_id ile kontrol kaydına yazılır.
     * Böylece kaydedilen analiz tarayıcıdan gelen veriye değil, sunucunun okuduğu sonuca dayanır.
     */
    public function rememberAnalysis(PkEquipment $equipment, array $result, ?User $user = null, ?string $fileHash = null, ?string $id = null): array
    {
        $id ??= (string) Str::uuid();
        Cache::put($this->analysisKey($id), [
            'tenant_id' => $this->tenantContext->id(),
            'equipment_id' => $equipment->id,
            'file_hash' => $fileHash,
            'model' => $result['fixture']['model'] ?? \App\Services\Ai\PkTakip\PkAiProvider::model(),
            'analyzed_at' => now()->toIso8601String(),
            // Test verisiyle yapıldıysa hangi fikstürden geldiği.
            'fixture' => $result['fixture'] ?? null,
            'duration_s' => $result['duration_s'] ?? null,
            'summary' => Arr::only($result, ['status', 'control_date', 'next_control_date', 'next_control_source', 'report_no', 'report_date', 'company_title', 'overall_text', 'findings', 'equipment', 'match']),
            // Kriterler hariç tüm analiz (rapor bilgileri, tesis bilgileri, sistemler, ekipman tanımları, bulgular, sonuç).
            'semantic' => $result['semantic'] ?? [],
        ], now()->addDay());

        return Arr::except($result, ['semantic']) + [
            'analysis_id' => $id,
            // Bu rapor daha önce yüklendiyse (rapor no ya da aynı dosya): pencere kaydetmeye izin vermez.
            'duplicate' => $this->duplicateReport($result['report_no'] ?? null, $fileHash),
            // Rapordaki özellikler mevcut değerlere göre: yeni / aynı / değişen (değişenler kullanıcıya sorulur).
            'properties' => $this->properties->preview($equipment, (array) ($result['semantic'] ?? []), (array) ($result['equipment'] ?? []), $result['control_date'] ?? null, $user),
        ];
    }

    private function variantsOf(int $typeId): array
    {
        return PeriodicEquipmentType::query()->whereKey($typeId)->value('variants') ?? [];
    }

    private function validVariant(int $typeId, ?string $variant): ?string
    {
        $variant = trim((string) $variant);
        if ($variant === '') {
            return null;
        }
        if (!in_array($variant, $this->variantsOf($typeId), true)) {
            throw ValidationException::withMessages(['variant' => 'Bu ekipman türü için geçersiz etiket.']);
        }

        return $variant;
    }

    private function cleanFindings(array $findings): array
    {
        return collect($findings)->map(fn ($finding) => trim((string) $finding))->filter()->values()->all();
    }

    private function analysisKey(string $id): string
    {
        return 'pk-report-analysis:' . $id;
    }

    private function cachedAnalysis(string $key, PkEquipment $equipment): ?array
    {
        $cached = Cache::get($key);
        if (!is_array($cached) || $cached['tenant_id'] !== $this->tenantContext->id() || ($cached['equipment_id'] !== null && $cached['equipment_id'] !== $equipment->id)) {
            return null;
        }

        return Arr::except($cached, ['tenant_id', 'equipment_id', 'file_hash']);
    }

    /**
     * Rapordan ekipman tanımlama: türü bilinmeyen analiz saklanır (henüz ekipmana bağlı değil); rapor zaten yüklendiyse
     * engel, mevcut ekipmanlarla eşleşme adayları ve yeni ekipman için rapordan öneri alanları döner.
     */
    public function rememberNewAnalysis(array $result, ?User $user = null, ?string $fileHash = null, ?int $locationId = null): array
    {
        $id = (string) Str::uuid();
        Cache::put($this->analysisKey($id), [
            'tenant_id' => $this->tenantContext->id(),
            'equipment_id' => null,
            'file_hash' => $fileHash,
            'model' => $result['fixture']['model'] ?? \App\Services\Ai\PkTakip\PkAiProvider::model(),
            'analyzed_at' => now()->toIso8601String(),
            'fixture' => $result['fixture'] ?? null,
            'duration_s' => $result['duration_s'] ?? null,
            'semantic' => $result['semantic'] ?? [],
        ], now()->addDay());

        $type = filled($result['detected_type']['id'] ?? null) ? PeriodicEquipmentType::find($result['detected_type']['id']) : null;
        $fields = $this->suggestedFields($result, $type);

        return Arr::only($result, ['detected_type', 'type_evidence', 'report_no', 'report_date', 'company_title', 'inspection_body', 'status', 'control_date', 'next_control_date', 'fixture', 'input']) + [
            'analysis_id' => $id,
            'duplicate' => $this->duplicateReport($result['report_no'] ?? null, $fileHash),
            'fields' => $fields,
            'candidates' => $this->reportCandidates($fields, $type, $locationId),
        ];
    }

    /**
     * Kayıtlı analizi bir ekipmana bağlar (eşleşen mevcut ekipman ya da yeni oluşturulan): Gemini'ye gitmeden
     * o ekipmana göre eşleşme, teknik özellik soruları ve öneri; rapor penceresi bununla açılır.
     */
    public function rebindAnalysis(string $id, PkEquipment $equipment, ?User $user = null): array
    {
        $cached = Cache::get($this->analysisKey($id));
        if (!is_array($cached) || $cached['tenant_id'] !== $this->tenantContext->id() || ($cached['equipment_id'] !== null && $cached['equipment_id'] !== $equipment->id)) {
            throw ValidationException::withMessages(['analysis_id' => 'Analiz bulunamadı ya da süresi doldu; raporu yeniden analiz edin.']);
        }
        $equipment->loadMissing(['type', 'workplace.businessEntity.company']);
        $result = app(\App\Services\Ai\PkTakip\PkInspectionReportReader::class)->fromSemantic((array) $cached['semantic'], $equipment)
            + ['fixture' => $cached['fixture'] ?? null, 'duration_s' => $cached['duration_s'] ?? null, 'semantic' => $cached['semantic']];

        return $this->rememberAnalysis($equipment, $result, $user, $cached['file_hash'] ?? null, $id);
    }

    /**
     * Rapordan yeni ekipman: ekipman henüz oluşturulmadan, formdaki taslağa (tür, no, marka, seri no...) göre eşleşme ve
     * teknik özellik soruları. Analiz ekipmana bağlanmaz; kayıtta ekipman ile rapor birlikte oluşturulur.
     */
    public function previewDraft(string $id, array $draft, ?User $user = null): array
    {
        $cached = Cache::get($this->analysisKey($id));
        if (!is_array($cached) || $cached['tenant_id'] !== $this->tenantContext->id() || $cached['equipment_id'] !== null) {
            throw ValidationException::withMessages(['analysis_id' => 'Analiz bulunamadı ya da süresi doldu; raporu yeniden analiz edin.']);
        }
        $equipment = new PkEquipment(Arr::only($draft, ['equipment_type_id', 'variant', 'code', 'brand', 'model', 'serial_no', 'place']));
        $equipment->setRelation('type', PeriodicEquipmentType::find($draft['equipment_type_id'] ?? null));
        $result = app(\App\Services\Ai\PkTakip\PkInspectionReportReader::class)->fromSemantic((array) $cached['semantic'], $equipment)
            + ['fixture' => $cached['fixture'] ?? null, 'duration_s' => $cached['duration_s'] ?? null, 'semantic' => $cached['semantic']];

        return $this->rememberAnalysis($equipment, $result, $user, $cached['file_hash'] ?? null, $id);
    }

    // Rapordan yeni ekipman: ekipman ve rapor (kontrol kaydı + teknik özellikler) tek işlemde; biri başarısızsa hiçbiri kalmaz.
    public function createFromReport(array $equipmentData, array $inspectionData, User $user, UploadedFile $report): PkEquipment
    {
        return DB::transaction(function () use ($equipmentData, $inspectionData, $user, $report) {
            $equipment = $this->create($equipmentData, $user);
            $this->addInspection($equipment, $inspectionData, $user, $report);

            return $equipment;
        });
    }

    // Yeni ekipman formu için rapordan: marka, model, seri no, ekipman no, bulunduğu yer ve etiket.
    private function suggestedFields(array $result, ?PeriodicEquipmentType $type): array
    {
        $equipment = new PkEquipment(['equipment_type_id' => $type?->id]);
        $equipment->setRelation('type', $type);
        $items = collect($this->properties->resolve($equipment, (array) ($result['semantic'] ?? []), (array) ($result['equipment'] ?? []))['items'])
            ->reject(fn ($item) => in_array(mb_strtolower(trim((string) $item['value'])), ['', '-', 'nu', 'yok'], true))
            ->keyBy('key');

        return [
            'brand' => $items['marka']['value'] ?? null,
            'model' => $items['model']['value'] ?? null,
            'serial_no' => $items['seri_no']['value'] ?? null,
            'code' => $items['ekipman_no']['value'] ?? ($result['match']['fields']['code'] ?? null),
            'place' => $items['bulundugu_yer']['value'] ?? null,
            'variant' => $result['match']['variant'] ?? null,
        ];
    }

    /**
     * Rapordaki ekipman kiracıda zaten var mı: raporda seri no varsa yalnızca seri no ile (seçili gelir). Seri no yoksa
     * aynı tür + türün kimlik alanları (ekipman no; yangın tüpünde bulunduğu yer + ekipman no + söndürücü tipi): seçili
     * çalışma alanının lokasyonundaysa seçili gelir, başka lokasyondaysa yalnızca öneri (no başka yerde tekrar edebilir).
     * Karşılaştırmada büyük/küçük harf, boşluk ve noktalama yok sayılır.
     */
    public function reportCandidates(array $fields, ?PeriodicEquipmentType $type, ?int $locationId = null): array
    {
        $token = fn ($value) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', \Illuminate\Support\Str::ascii((string) $value)));
        $serial = $token($fields['serial_no'] ?? '');
        $found = [];

        if ($serial !== '') {
            foreach (PkEquipment::query()->whereNotNull('serial_no')->with(['type', 'location:id,name', 'workplace.businessEntity.company'])->get() as $equipment) {
                if ($token($equipment->serial_no) === $serial) {
                    $found[$equipment->id] = [$equipment, 'Seri no aynı', true];
                }
            }
        }
        $identity = \App\Services\Ai\PkTakip\PkReportEquipmentMatcher::identityFields($type?->slug);
        $wanted = collect($identity)->mapWithKeys(fn (string $field) => [$field => $token($fields[$field] ?? '')]);
        if ($serial === '' && $type && $wanted->every(fn (string $value) => $value !== '')) {
            $label = match ($identity) {
                ['code'] => 'aynı tür ve ekipman no',
                ['place', 'code'] => 'aynı bulunduğu yer ve ekipman no',
                default => 'aynı bulunduğu yer, ekipman no ve tip',
            };
            foreach (PkEquipment::query()->where('equipment_type_id', $type->id)->with(['type', 'location:id,name', 'workplace.businessEntity.company'])->get() as $equipment) {
                if ($wanted->every(fn (string $value, string $field) => $token($equipment->{$field}) === $value)) {
                    $sameLocation = $locationId !== null && $equipment->location_id === $locationId;
                    $found[$equipment->id] ??= [$equipment, 'Raporda seri no yok — ' . $label . ($sameLocation ? '' : ' (başka lokasyonda)'), $sameLocation];
                }
            }
        }

        return collect($found)->map(function (array $entry) {
            [$equipment, $reason, $strong] = $entry;
            $business = $equipment->workplace?->businessEntity;

            return [
                'id' => $equipment->id,
                'name' => $equipment->name ?: $equipment->type?->name,
                'type' => $equipment->type?->name,
                'variant' => $equipment->variant,
                'code' => $equipment->code,
                'brand' => $equipment->brand,
                'model' => $equipment->model,
                'serial_no' => $equipment->serial_no,
                'location' => $equipment->location?->name,
                'workplace' => $business?->company?->name ?? $business?->name,
                'reason' => $reason,
                'strong' => $strong,
            ];
        })->sortByDesc('strong')->values()->all();
    }

    /**
     * Aynı rapor daha önce yüklendi mi (kiracı içinde): rapor no ya da dosya özeti eşleşen kontrol kaydı.
     * @return array{inspection_id: int, equipment_id: int, message: string}|null
     */
    public function duplicateReport(?string $reportNo, ?string $hash): ?array
    {
        $reportNo = trim((string) $reportNo);
        if ($reportNo === '' && blank($hash)) {
            return null;
        }
        $existing = PkInspection::query()
            ->where(fn ($query) => $query
                ->when($reportNo !== '', fn ($q) => $q->orWhere('report_no', $reportNo))
                ->when(filled($hash), fn ($q) => $q->orWhere('report_hash', $hash)))
            ->with('equipment.type')
            ->orderBy('id')
            ->first();
        if (!$existing) {
            return null;
        }
        $equipment = $existing->equipment;
        $label = $equipment ? ($equipment->name ?: $equipment->type?->name) . ($equipment->code ? " (no {$equipment->code})" : '') : 'bir ekipman';
        $by = $reportNo !== '' && $existing->report_no === $reportNo ? "rapor no {$reportNo}" : 'aynı dosya';

        return [
            'inspection_id' => $existing->id,
            'equipment_id' => $existing->pk_equipment_id,
            'message' => "Bu rapor zaten yüklenmiş ({$by}): {$label}, kontrol tarihi " . ($existing->control_date?->format('d.m.Y') ?? '—') . '.',
        ];
    }

    /**
     * Kontrol kaydını siler: rapor dosyası, o rapordan gelen teknik özellik değerleri (özellikler bir önceki duruma döner)
     * ve o rapordan açılmış, onay bekleyen katalog talepleri de silinir. Onaylanmış katalog özellikleri kalır.
     */
    public function deleteInspection(PkEquipment $equipment, PkInspection $inspection): void
    {
        if ($inspection->pk_equipment_id !== $equipment->id) {
            abort(404);
        }
        $file = $inspection->report_file;
        DB::transaction(function () use ($inspection) {
            PkEquipmentPropertyValue::query()->where('pk_inspection_id', $inspection->id)->delete();
            PeriodicEquipmentSpecRequest::query()->where('pk_inspection_id', $inspection->id)->where('status', 'pending')->delete();
            $inspection->delete();
        });
        if ($file) {
            Storage::disk('local')->delete($file);
        }
    }

    /**
     * Kontrol kaydını düzenler (elle girişteki hata, eksik bulgu). Rapor dosyası ve yapay zeka analizi değişmez.
     * Raporlu kayıt "rapor bekleniyor" yapılamaz; rapor bekleniyor kaydının sonucu rapor gelince yüklenir.
     */
    public function updateInspection(PkEquipment $equipment, PkInspection $inspection, array $data): void
    {
        if ($inspection->pk_equipment_id !== $equipment->id) {
            abort(404);
        }
        $pending = $inspection->status === 'rapor_bekleniyor';
        if ($pending !== ($data['status'] === 'rapor_bekleniyor')) {
            throw ValidationException::withMessages(['status' => $pending
                ? 'Sonuç, rapor gelince "Yeni Periyodik Kontrol Raporu Yükle" ile girilir.'
                : 'Raporlu kontrol kaydı "rapor bekleniyor" yapılamaz.']);
        }
        if (empty($data['control_date'])) {
            throw ValidationException::withMessages(['control_date' => 'Kontrol tarihi zorunludur.']);
        }
        // Rapor no benzersiz: başka bir kayıtta varsa kaydedilmez.
        $reportNo = $pending ? null : (mb_substr(trim((string) ($data['report_no'] ?? '')), 0, 100) ?: null);
        if ($reportNo !== null && $reportNo !== $inspection->report_no && ($duplicate = $this->duplicateReport($reportNo, null))) {
            throw ValidationException::withMessages(['report_no' => $duplicate['message']]);
        }

        $controlDate = CarbonImmutable::parse($data['control_date'])->startOfDay();
        $dateChanged = $inspection->control_date?->format('Y-m-d') !== $controlDate->format('Y-m-d');
        DB::transaction(function () use ($inspection, $data, $pending, $reportNo, $controlDate, $dateChanged) {
            $inspection->update([
                'control_date' => $controlDate->format('Y-m-d'),
                'next_control_date' => $pending ? null : ($data['next_control_date'] ?? null),
                'status' => $data['status'],
                'report_no' => $reportNo,
                'inspection_body' => trim((string) ($data['inspection_body'] ?? '')) ?: null,
                'note' => $pending ? (trim((string) ($data['note'] ?? '')) ?: null) : $inspection->note,
                'conclusion' => $pending ? null : (trim((string) ($data['conclusion'] ?? '')) ?: null),
                'findings' => $pending ? null : ($this->cleanFindings($data['findings'] ?? []) ?: null),
            ]);
            // Bu rapordan gelen teknik özellik değerleri kontrol tarihinden geçerli: tarih düzeltilince onlar da düzelir.
            if ($dateChanged) {
                PkEquipmentPropertyValue::query()->where('pk_inspection_id', $inspection->id)->update(['effective_at' => $controlDate]);
            }
        });
    }

    // Kontrol raporu dosyası (private disk): [mutlak yol, indirme adı].
    public function reportFile(PkEquipment $equipment, PkInspection $inspection): array
    {
        if ($inspection->pk_equipment_id !== $equipment->id || !$inspection->report_file || !Storage::disk('local')->exists($inspection->report_file)) {
            abort(404, 'Rapor dosyası bulunamadı.');
        }

        return [Storage::disk('local')->path($inspection->report_file), $inspection->report_file_name ?: basename($inspection->report_file)];
    }

    private function reportDir(int $equipmentId): string
    {
        return "pk-inspections/{$equipmentId}";
    }

    // Toplu rapordan (tesisat raporu, tüp kontrol formu) ekipmanın kontrol kaydı için rapor dosyasının bağımsız bağlantısı
    // (diskte yer kaplamaz; bağlantı desteklenmiyorsa kopya). Ekipmanın kendi klasöründe: ekipmanın kontrolü silinince
    // yalnızca bu bağlantı silinir, rapor kalır.
    public function linkReportFile(string $source, int $equipmentId): string
    {
        $disk = Storage::disk('local');
        $target = $this->reportDir($equipmentId) . '/' . Str::uuid() . '.' . (pathinfo($source, PATHINFO_EXTENSION) ?: 'pdf');
        $disk->makeDirectory(dirname($target));
        if (!@link($disk->path($source), $disk->path($target))) {
            $disk->copy($source, $target);
        }

        return $target;
    }

    /** Ekipmanın bağlı olduğu tesisat (yoksa null); bkz. installationResolver. */
    public function installationOf(PkEquipment $equipment): ?array
    {
        return $this->installationResolver(collect([$equipment]))($equipment);
    }

    /**
     * Tesisata bağlı ekipman: aynı lokasyonda, sistemlerinden birinin ekipman türü bu ekipmanın türü olan tesisat (tesisat
     * ekranında o sistemin altında listelenen ekipmanlar). Lokasyon geneli tesisat lokasyonun tüm ekipmanlarını, işyerine
     * ait tesisat o işyerininkileri ve lokasyon genelini kapsar. Bağlı ekipmanın kontrolü tesisat raporundan gelir; ekipman
     * bazında rapor yükleme ve rapor bekleniyor yapılmaz. Tesisat silinir ya da sistem çıkarılırsa bağ kalkar.
     *
     * @return \Closure(PkEquipment): (array{id: int, name: string}|null)
     */
    private function installationResolver(Collection $equipment): \Closure
    {
        $installations = $equipment->isEmpty() ? collect() : PkInstallation::query()
            ->whereIn('location_id', $equipment->pluck('location_id')->unique()->values())
            ->with(['type:id,name', 'systems.catalog.equipmentTypes'])
            ->orderByRaw('location_business_entity_id is not null')->orderBy('id')
            ->get();

        return function (PkEquipment $item) use ($installations): ?array {
            $installation = $installations->first(fn (PkInstallation $installation) => (int) $installation->location_id === (int) $item->location_id
                && ($installation->location_business_entity_id === null || $item->location_business_entity_id === null
                    || (int) $installation->location_business_entity_id === (int) $item->location_business_entity_id)
                && $installation->systems->contains(fn ($system) => (bool) $system->catalog?->equipmentTypes->contains('id', $item->equipment_type_id)));

            return $installation ? ['id' => $installation->id, 'name' => $installation->name ?: $installation->type?->name] : null;
        };
    }

    private function present(PkEquipment $equipment): array
    {
        $latest = $equipment->latestInspection;
        $nextDate = $latest?->next_control_date ? CarbonImmutable::parse($latest->next_control_date) : null;
        $business = $equipment->workplace?->businessEntity;

        return [
            'id' => $equipment->id,
            'name' => $equipment->name,
            'code' => $equipment->code,
            'serial_no' => $equipment->serial_no,
            'brand' => $equipment->brand,
            'model' => $equipment->model,
            'place' => $equipment->place,
            'notes' => $equipment->notes,
            // APP_URL yerine isteğin kendi adresi: backend farklı port/alan adında çalışabiliyor.
            'photo_url' => $equipment->photo_path ? request()->getSchemeAndHttpHost() . '/storage/' . $equipment->photo_path : null,
            'variant' => $equipment->variant,
            'type' => $equipment->type ? ['id' => $equipment->type->id, 'name' => $equipment->type->name, 'default_period_months' => $equipment->type->default_period_months, 'variants' => $equipment->type->variants ?? [], 'variant_label' => $equipment->type->variant_label] : null,
            'category' => $equipment->type?->category ? ['slug' => $equipment->type->category->slug, 'name' => $equipment->type->category->name] : null,
            'location' => $equipment->location ? ['id' => $equipment->location->id, 'name' => $equipment->location->name] : null,
            'workplace' => $equipment->workplace ? ['id' => $equipment->workplace->id, 'company_name' => $business?->company?->name ?? $business?->name] : null,
            'last_control_date' => $latest?->control_date?->format('Y-m-d'),
            'next_control_date' => $nextDate?->format('Y-m-d'),
            // Pasif ekipman kalan gün / süresi geçmiş hesabına girmez.
            'days_left' => $nextDate && $equipment->is_active !== false ? (int) CarbonImmutable::today()->diffInDays($nextDate, false) : null,
            'is_active' => $equipment->is_active !== false,
            'deactivated_at' => $equipment->deactivated_at?->format('Y-m-d'),
            'deactivation_reason' => $equipment->deactivation_reason,
            'deactivation_note' => $equipment->deactivation_note,
            // rapor_yok: hiç kontrol kaydı yok.
            'status' => $latest?->status ?? 'rapor_yok',
            'inspections_count' => $equipment->inspections_count ?? 0,
        ];
    }

    private function presentInspection(PkInspection $inspection, PkEquipment $equipment): array
    {
        return [
            'id' => $inspection->id,
            'control_date' => $inspection->control_date?->format('Y-m-d'),
            'next_control_date' => $inspection->next_control_date?->format('Y-m-d'),
            'status' => $inspection->status,
            'source' => $inspection->source,
            'note' => $inspection->note,
            'inspection_body' => $inspection->inspection_body,
            'conclusion' => $inspection->conclusion,
            'findings' => $inspection->findings ?? [],
            'has_report' => (bool) $inspection->report_file,
            'report_file_name' => $inspection->report_file_name,
            'report_no' => $inspection->report_no,
            // Tesisat raporundan geldi: buradan silinmez, tesisat raporu silinince silinir.
            'from_installation' => (bool) ($inspection->installation_reports_exists ?? false),
            // Tüp kontrol formundan geldi: buradan silinmez, form silinince silinir.
            'from_bulk' => (bool) ($inspection->bulk_reports_exists ?? false),
            'analysis' => $inspection->analysis ? $this->presentAnalysis($inspection->analysis) + [
                // Katalogda olmayan özellikler: kataloğa eklenmesi buradan talep edilir.
                'unmapped_specs' => $this->properties->unmappedOf($equipment, $inspection),
            ] : null,
            'created_by' => $inspection->creator?->name,
            'created_at' => $inspection->created_at,
        ];
    }

    // Kaydedilmiş yapay zeka analizinin ekranda gösterilen kısmı (ham analiz veritabanında kalır).
    private function presentAnalysis(array $analysis): array
    {
        $data = (array) ($analysis['semantic']['extracted_data'] ?? []);
        $values = fn (array $rows) => collect($rows)
            ->filter(fn ($row) => is_array($row) && filled($row['value'] ?? null))
            ->map(fn ($row) => ['key' => $row['key'] ?? null, 'value' => $row['value']])
            ->values();

        return [
            'model' => $analysis['model'] ?? null,
            'analyzed_at' => $analysis['analyzed_at'] ?? null,
            'fixture' => $analysis['fixture'] ?? null,
            'report_information' => $values((array) ($data['report_information'] ?? [])),
            'facility_information' => $values((array) ($data['facility_information'] ?? [])),
            'overall_result' => $data['overall_result'] ?? null,
            'inspection_body' => $data['inspection_body'] ?? null,
            'findings' => collect((array) ($data['findings'] ?? []))
                ->map(fn ($finding) => Arr::only((array) $finding, ['description', 'system_name', 'severity']))
                ->values(),
            'systems' => collect((array) ($analysis['semantic']['template']['fire_systems']['systems'] ?? []))
                ->map(fn ($system) => ['name' => $system['system_name'] ?? null, 'status' => $system['verdict']['status'] ?? null])
                ->values(),
            'equipment' => $analysis['summary']['equipment'] ?? [],
            'match' => $analysis['summary']['match'] ?? null,
        ];
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
