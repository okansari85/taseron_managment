<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Location;
use App\Models\PkBulkReport;
use App\Models\PkBulkReportPendingRow;
use App\Models\PkBulkReportScope;
use App\Models\PkEquipment;
use App\Models\PkEquipmentPropertyValue;
use App\Models\PkInspection;
use App\Models\User;
use App\Services\Ai\PkTakip\PkBulkReportReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Tüp kontrol formunda eşleştirme: kayıtta yalnızca Ekipmanlar'daki bir tüple eşleştirilen satırlara kontrol kaydı açılır;
 * eşleşmeyen satırlar tüp eklenmeden "eşleştirme bekliyor" olarak formla saklanır (pk_bulk_report_pending_rows). Sonradan
 * satır mevcut bir tüpe bağlanır, yeni tüp olarak eklenir ya da yok sayılır. Ayrıca formla açılmış (başka kontrol kaydı
 * olmayan) bir tüp, aslında olduğu mevcut tüpe taşınır. PkBulkReportService::save() değişmez; kayıt adımı onun kopyasıdır.
 */
class PkBulkReportMatchService
{
    public function __construct(
        private TenantContext $tenantContext,
        private PkBulkReportService $forms,
        private PkEquipmentService $equipment,
        private PkEquipmentPropertyService $properties,
        private PkReportAnalysisHistory $history,
        private PkBulkReportReader $reader
    ) {
    }

    /**
     * Formu kaydeder (PkBulkReportService::save kopyası): eşleştirilen satırlar mevcut tüplere kontrol kaydı olur, eşleşmeyenler
     * eşleştirme bekleyen satır olarak saklanır; tüp eklenmez. Hiç eşleşen olmasa da form kaydedilir (ilk form).
     */
    public function save(array $data, UploadedFile $file, User $user): PkBulkReport
    {
        $type = $this->reader->type();
        $locationId = (int) $data['location_id'];
        if (!Location::query()->whereKey($locationId)->exists()) {
            throw ValidationException::withMessages(['location_id' => 'Lokasyon bulunamadı.']);
        }

        // PkBulkReportService::analysisKey ile aynı anahtar (okuma oradan saklanır).
        $analysisKey = !empty($data['analysis_id']) ? 'pk-bulk-analysis:' . $data['analysis_id'] : null;
        $analysis = $analysisKey ? Cache::get($analysisKey) : null;
        if ($analysisKey && (!is_array($analysis) || $analysis['tenant_id'] !== $this->tenantContext->id())) {
            throw ValidationException::withMessages(['analysis_id' => 'Yapay zeka analizinin süresi doldu; raporu yeniden okuyun.']);
        }

        $reportNo = mb_substr(trim((string) ($data['report_no'] ?? '')), 0, 100) ?: null;
        $hash = hash_file('sha256', $file->getRealPath());
        if ($duplicate = $this->forms->duplicate($reportNo, $hash)) {
            throw ValidationException::withMessages(['report' => $duplicate['message']]);
        }
        $rows = $this->cleanRows((array) ($data['rows'] ?? []), $locationId, $type->id);
        $pending = array_map(fn (array $row) => $this->cleanRow($row), array_values(array_filter((array) ($data['pending_rows'] ?? []), 'is_array')));
        if ($rows === [] && $pending === []) {
            throw ValidationException::withMessages(['rows' => 'Kaydedilecek tüp yok: en az bir satır eşleştirilmeli ya da eşleştirme bekliyor olmalı.']);
        }

        $path = $file->store('pk-bulk-reports/' . $this->tenantContext->id(), 'local');
        $linked = [];
        try {
            $report = DB::transaction(function () use ($data, $type, $locationId, $analysis, $reportNo, $hash, $rows, $pending, $file, $path, $user, &$linked) {
                $report = PkBulkReport::create([
                    'tenant_id' => $this->tenantContext->id(),
                    'location_id' => $locationId,
                    // Tüpler lokasyonun tamamına kayıtlıdır (firma bazında değil).
                    'location_business_entity_id' => null,
                    'equipment_type_id' => $type->id,
                    'control_date' => $data['control_date'],
                    'next_control_date' => $data['next_control_date'] ?? null,
                    'status' => in_array($data['status'] ?? null, ['uygun', 'uygun_degil'], true) ? $data['status'] : null,
                    'source' => $analysis ? 'ai' : 'manual',
                    'report_file' => $path,
                    'report_file_name' => $file->getClientOriginalName(),
                    'report_no' => $reportNo,
                    'report_hash' => $hash,
                    'inspection_body' => trim((string) ($data['inspection_body'] ?? '')) ?: null,
                    'conclusion' => trim((string) ($data['conclusion'] ?? '')) ?: null,
                    'systems' => $this->cleanSystems((array) ($data['systems'] ?? [])) ?: null,
                    'findings' => $this->cleanTexts((array) ($data['findings'] ?? [])) ?: null,
                    'analysis' => $analysis ? Arr::only($analysis, ['model', 'analyzed_at', 'fixture', 'duration_s', 'input', 'semantic', 'tables']) : null,
                    'created_by' => $user->id,
                ]);
                foreach ($rows as $row) {
                    $equipment = $this->activeTube($report, $row['equipment_id']);
                    $this->addInspection($report, $equipment, $row, false, $user, $linked);
                    // Elle eşleştirilen tüp: istenirse yeri ve no'su formdaki gibi.
                    if ($row['update_place']) {
                        $this->updatePlace($equipment, $row['place'], $row['code']);
                    }
                }
                foreach ($pending as $row) {
                    PkBulkReportPendingRow::create(['pk_bulk_report_id' => $report->id, 'row' => $row]);
                }
                // Formun türü: tüm envanter ya da ek form (eşleştirme ekranı son tüm envanter formuna göre).
                PkBulkReportScope::create([
                    'pk_bulk_report_id' => $report->id,
                    'scope' => ($data['form_scope'] ?? null) === PkBulkReportScope::PARTIAL ? PkBulkReportScope::PARTIAL : PkBulkReportScope::FULL,
                ]);

                return $report;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete([$path, ...$linked]);
            throw $exception;
        }
        if ($analysisKey) {
            Cache::forget($analysisKey);
            rescue(fn () => $this->history->markSaved($data['analysis_id'], ['pk_bulk_report_id' => $report->id], $path));
        }

        return $report;
    }

    /**
     * Formun eşleştirme bilgisi: bekleyen satırlar, adayları (lokasyondaki aktif tüplerden bu formda kontrol kaydı olmayanlar)
     * ve taşınabilir kontrol kayıtları (formla açılmış, başka kontrol kaydı olmayan tüpler: kontrol kaydı id'si → tüp id'si).
     */
    public function matching(PkBulkReport $report): array
    {
        $inForm = $report->inspections()->pluck('pk_inspections.pk_equipment_id', 'pk_inspections.id');
        $counts = $inForm->isEmpty() ? collect() : PkInspection::query()->whereIn('pk_equipment_id', $inForm->values()->unique()->all())
            ->selectRaw('pk_equipment_id, count(*) as total')->groupBy('pk_equipment_id')->pluck('total', 'pk_equipment_id');
        $taken = array_flip($inForm->values()->all());

        return [
            'pending_rows' => PkBulkReportPendingRow::query()->where('pk_bulk_report_id', $report->id)->orderBy('id')->get()
                ->map(fn (PkBulkReportPendingRow $row) => ['id' => $row->id] + (array) $row->row)->values()->all(),
            'candidates' => $this->equipment->list(['location_id' => $report->location_id, 'type_id' => $report->equipment_type_id])
                ->filter(fn (array $item) => $item['is_active'] && !isset($taken[$item['id']]))
                ->map(fn (array $item) => Arr::only($item, ['id', 'code', 'place', 'variant', 'status', 'last_control_date']))
                ->values()->all(),
            'movable' => $inForm->filter(fn ($equipmentId) => (int) ($counts[$equipmentId] ?? 0) === 1)->map(fn ($equipmentId) => (int) $equipmentId)->all(),
        ];
    }

    /**
     * Mevcut envanter ile son tüm envanter formu arasındaki fark ("Eşleştirme bekliyor" sekmesi). left (envanterden
     * çıkarılacaklar): aktif tüplerden son tüm envanter formunda olmayanlar; o form yüklendikten sonra envantere giren ya da
     * kontrol kaydı alan tüpler (ek form, elle ekleme, tek rapor) sayılmaz. right (yeni eklenecekler): bütün formlarda
     * Ekipmanlar'daki bir tüple eşleşmeyen satırlar. Ek formlar sol listeye bir şey eklemez.
     */
    public function board(int $locationId): array
    {
        $type = $this->reader->type();
        $forms = PkBulkReport::query()->where('location_id', $locationId)->orderByDesc('control_date')->orderByDesc('id')->get();
        if (!$type || $forms->isEmpty()) {
            return ['form' => null, 'forms' => [], 'left' => [], 'right' => []];
        }
        $scopes = $this->scopesOf($forms->pluck('id')->all());
        $full = $forms->first(fn (PkBulkReport $form) => ($scopes[$form->id] ?? PkBulkReportScope::FULL) === PkBulkReportScope::FULL);
        $left = [];
        if ($full) {
            $tubes = PkEquipment::query()->where('location_id', $locationId)->where('equipment_type_id', $type->id);
            $inForm = $full->inspections()->pluck('pk_inspections.pk_equipment_id')->flip();
            $before = (clone $tubes)->where('created_at', '<', $full->created_at)->pluck('id')->flip();
            $touched = PkInspection::query()->where('created_at', '>=', $full->created_at)->whereIn('pk_equipment_id', (clone $tubes)->select('id'))
                ->pluck('pk_equipment_id')->flip();
            $left = $this->equipment->list(['location_id' => $locationId, 'type_id' => $type->id])
                ->filter(fn (array $item) => $item['is_active'] && isset($before[$item['id']]) && !isset($inForm[$item['id']]) && !isset($touched[$item['id']]))
                ->map(fn (array $item) => Arr::only($item, ['id', 'code', 'place', 'variant', 'status', 'last_control_date']))
                ->values()->all();
        }
        $label = fn (PkBulkReport $form) => [
            'id' => $form->id, 'control_date' => $form->control_date?->format('Y-m-d'), 'report_no' => $form->report_no,
            'report_file_name' => $form->report_file_name, 'scope' => $scopes[$form->id] ?? PkBulkReportScope::FULL,
        ];

        return [
            'form' => $full ? $label($full) : null,
            'forms' => $forms->map($label)->values()->all(),
            'left' => $left,
            'right' => PkBulkReportPendingRow::query()->whereIn('pk_bulk_report_id', $forms->pluck('id'))->orderBy('id')->get()
                ->map(fn (PkBulkReportPendingRow $row) => ['id' => $row->id, 'form_id' => $row->pk_bulk_report_id] + (array) $row->row)
                ->values()->all(),
        ];
    }

    // Lokasyondaki formların türü (form id → full / partial; kaydı olmayan tüm envanter).
    public function scopes(int $locationId): array
    {
        $ids = PkBulkReport::query()->where('location_id', $locationId)->pluck('id')->all();
        $scopes = $this->scopesOf($ids);

        return collect($ids)->mapWithKeys(fn ($id) => [$id => $scopes[$id] ?? PkBulkReportScope::FULL])->all();
    }

    public function setScope(PkBulkReport $report, string $scope): void
    {
        PkBulkReportScope::query()->updateOrCreate(['pk_bulk_report_id' => $report->id], ['scope' => $scope]);
    }

    private function scopesOf(array $ids): array
    {
        return $ids ? PkBulkReportScope::query()->whereIn('pk_bulk_report_id', $ids)->pluck('scope', 'pk_bulk_report_id')->all() : [];
    }

    // Tablodan eşleştirme: bekleyen satır (hangi formdaysa) → mevcut tüp; tüp yeni rapordaki adı (yer, no) alır.
    public function matchBoardRow(int $rowId, int $equipmentId, User $user): void
    {
        $row = PkBulkReportPendingRow::query()->whereHas('report')->findOrFail($rowId);
        $this->matchRow($row->report, $row, $equipmentId, true, $user);
    }

    // Tablodan yeni tüp: seçilen bekleyen satırlar (formlarına göre) Ekipmanlar'a eklenir.
    public function addBoardRows(array $ids, User $user): int
    {
        $rows = PkBulkReportPendingRow::query()->whereHas('report')->whereIn('id', array_map('intval', $ids))->with('report')->get();
        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['ids' => 'Eşleştirme bekleyen satır bulunamadı.']);
        }
        $added = 0;
        foreach ($rows->groupBy('pk_bulk_report_id') as $group) {
            $added += $this->addRows($group->first()->report, $group->pluck('id')->all(), $user);
        }

        return $added;
    }

    // Lokasyondaki formlarda eşleştirme bekleyen satır sayısı (form id → sayı).
    public function pendingCounts(int $locationId): array
    {
        return PkBulkReportPendingRow::query()
            ->whereHas('report', fn ($query) => $query->where('location_id', $locationId))
            ->selectRaw('pk_bulk_report_id, count(*) as total')->groupBy('pk_bulk_report_id')
            ->pluck('total', 'pk_bulk_report_id')->map(fn ($total) => (int) $total)->all();
    }

    /**
     * Bekleyen satırı mevcut tüpe bağlar: tüpe formun tarihleriyle, satırın sonucuyla kontrol kaydı açılır. İstenirse tüpün yeri
     * ve no'su formdaki gibi güncellenir (boş değerler yazılmaz).
     */
    public function matchRow(PkBulkReport $report, PkBulkReportPendingRow $pending, int $equipmentId, bool $updatePlace, User $user): void
    {
        $this->assertRow($report, $pending);
        $linked = [];
        try {
            DB::transaction(function () use ($report, $pending, $equipmentId, $updatePlace, $user, &$linked) {
                $equipment = $this->freeTube($report, $equipmentId);
                $row = (array) $pending->row;
                $this->addInspection($report, $equipment, $row, false, $user, $linked);
                if ($updatePlace) {
                    $this->updatePlace($equipment, $row['place'] ?? null, $row['code'] ?? null);
                }
                $pending->delete();
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($linked);
            throw $exception;
        }
    }

    // Bekleyen satırlar yeni tüp olarak eklenir (Ekipmanlar'a, lokasyon geneli); her birine bu formla kontrol kaydı.
    public function addRows(PkBulkReport $report, array $ids, User $user): int
    {
        $rows = $this->pendingRows($report, $ids);
        $type = $this->reader->type();
        $linked = [];
        try {
            DB::transaction(function () use ($report, $rows, $type, $user, &$linked) {
                foreach ($rows as $pending) {
                    $row = (array) $pending->row;
                    $equipment = $this->equipment->create([
                        'location_id' => $report->location_id,
                        'location_business_entity_id' => null,
                        'equipment_type_id' => $type->id,
                        'variant' => in_array($row['variant'] ?? null, (array) ($type->variants ?? []), true) ? $row['variant'] : null,
                        'code' => $row['code'] ?? null,
                        'place' => $row['place'] ?? null,
                        'brand' => $row['brand'] ?? null,
                        'model' => $row['model'] ?? null,
                        'serial_no' => $row['serial_no'] ?? null,
                    ], $user);
                    $this->addInspection($report, $equipment, $row, true, $user, $linked);
                    $pending->delete();
                }
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($linked);
            throw $exception;
        }

        return $rows->count();
    }

    // Bekleyen satırlar yok sayılır (silinir); tüp eklenmez.
    public function skipRows(PkBulkReport $report, array $ids): int
    {
        $rows = $this->pendingRows($report, $ids);
        PkBulkReportPendingRow::query()->whereIn('id', $rows->pluck('id'))->delete();

        return $rows->count();
    }

    /**
     * Formla açılmış tüp aslında mevcut bir tüp: formun kontrol kaydı (rapor dosyası ve rapordan okunan özelliklerle) o tüpe
     * taşınır, formla açılan kayıt silinir. Yalnızca başka kontrol kaydı olmayan tüp taşınır; hedef aynı lokasyonda, aynı türde,
     * aktif ve bu formda kontrol kaydı olmayan bir tüp olmalı. Özellikler hedefte boşsa geçerli olur (eşleştirmedeki gibi).
     */
    public function moveInspection(PkBulkReport $report, PkInspection $inspection, int $equipmentId, bool $updatePlace, User $user): void
    {
        if (!$report->inspections()->where('pk_inspections.id', $inspection->id)->exists()) {
            abort(404);
        }
        $old = $inspection->equipment;
        if (!$old || PkInspection::query()->where('pk_equipment_id', $old->id)->count() !== 1) {
            throw ValidationException::withMessages(['equipment_id' => 'Bu tüpün başka kontrol kayıtları var; yalnızca bu formla açılmış tüpler taşınabilir.']);
        }
        $linked = [];
        try {
            DB::transaction(function () use ($report, $inspection, $old, $equipmentId, $updatePlace, $user, &$linked) {
                $target = $this->freeTube($report, $equipmentId);
                if ($target->id === $old->id) {
                    throw ValidationException::withMessages(['equipment_id' => 'Aynı tüp seçildi.']);
                }
                $values = PkEquipmentPropertyValue::query()->where('pk_inspection_id', $inspection->id)->where('source', 'report')->get();
                $properties = $values->filter(fn (PkEquipmentPropertyValue $value) => filled($value->raw_field) && filled($value->raw_value))
                    ->mapWithKeys(fn (PkEquipmentPropertyValue $value) => [$value->raw_field => $value->raw_value])->all();
                PkEquipmentPropertyValue::query()->whereIn('id', $values->pluck('id'))->delete();
                $linked[] = $file = $this->equipment->linkReportFile($report->report_file, $target->id);
                $inspection->update(['pk_equipment_id' => $target->id, 'report_file' => $file]);
                if ($properties) {
                    $this->properties->recordTableRow($target, $inspection, $properties, $report->report_no, false, $user);
                }
                if ($updatePlace) {
                    $this->updatePlace($target, $old->place, $old->code);
                }
                // Eski bağlantı dosyası eski tüpün klasöründe; tüp silinince klasörüyle silinir.
                $this->equipment->delete($old);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($linked);
            throw $exception;
        }
    }

    // Kontrol kaydı (PkBulkReportService::save'deki satır adımının kopyası): rapor dosyası bağlantısı, kayıt, form bağı, özellikler.
    private function addInspection(PkBulkReport $report, PkEquipment $equipment, array $row, bool $newEquipment, User $user, array &$linked): PkInspection
    {
        $linked[] = $reportFile = $this->equipment->linkReportFile($report->report_file, $equipment->id);
        $inspection = PkInspection::create([
            'tenant_id' => $this->tenantContext->id(),
            'pk_equipment_id' => $equipment->id,
            'control_date' => $report->control_date,
            'next_control_date' => $report->next_control_date,
            'status' => in_array($row['status'] ?? null, ['uygun', 'uygun_degil'], true) ? $row['status'] : 'belirtilmemis',
            'source' => $report->source,
            'report_file' => $reportFile,
            'report_file_name' => $report->report_file_name,
            'report_no' => $report->report_no,
            'report_hash' => $report->report_hash,
            'inspection_body' => $report->inspection_body,
            'findings' => ($row['findings'] ?? []) ?: null,
            'created_by' => $user->id,
        ]);
        $report->inspections()->attach($inspection->id);
        if (!empty($row['properties'])) {
            $this->properties->recordTableRow($equipment, $inspection, (array) $row['properties'], $report->report_no, $newEquipment, $user);
        }

        return $inspection;
    }

    private function updatePlace(PkEquipment $equipment, ?string $place, ?string $code): void
    {
        $data = array_filter(['place' => trim((string) $place) ?: null, 'code' => trim((string) $code) ?: null], fn ($value) => $value !== null);
        if ($data) {
            $this->equipment->update($equipment, $data);
        }
    }

    // Formun lokasyonunda, formun türünde aktif tüp.
    private function activeTube(PkBulkReport $report, int $equipmentId): PkEquipment
    {
        $equipment = PkEquipment::query()->where('location_id', $report->location_id)->where('equipment_type_id', $report->equipment_type_id)
            ->where('is_active', true)->find($equipmentId);
        if (!$equipment) {
            throw ValidationException::withMessages(['equipment_id' => 'Seçilen tüp bu lokasyonda bulunamadı ya da pasif.']);
        }

        return $equipment;
    }

    // Aktif ve bu formda henüz kontrol kaydı olmayan tüp (aynı tüp formda iki kez geçemez).
    private function freeTube(PkBulkReport $report, int $equipmentId): PkEquipment
    {
        $equipment = $this->activeTube($report, $equipmentId);
        if ($report->inspections()->where('pk_inspections.pk_equipment_id', $equipment->id)->exists()) {
            throw ValidationException::withMessages(['equipment_id' => 'Bu tüpün bu formda zaten kontrol kaydı var.']);
        }

        return $equipment;
    }

    private function assertRow(PkBulkReport $report, PkBulkReportPendingRow $row): void
    {
        if ($row->pk_bulk_report_id !== $report->id) {
            abort(404);
        }
    }

    private function pendingRows(PkBulkReport $report, array $ids)
    {
        $rows = PkBulkReportPendingRow::query()->where('pk_bulk_report_id', $report->id)->whereIn('id', array_map('intval', $ids))->orderBy('id')->get();
        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['ids' => 'Eşleştirme bekleyen satır bulunamadı.']);
        }

        return $rows;
    }

    /**
     * Eşleştirilen satırlar (PkBulkReportService::cleanRows kopyası; burada her satırda mevcut tüp seçili): aynı lokasyonda,
     * aynı türde, aktif; aynı tüp iki satırda seçilemez.
     */
    private function cleanRows(array $rows, int $locationId, int $typeId): array
    {
        $existing = array_flip(PkEquipment::query()->where('location_id', $locationId)->where('equipment_type_id', $typeId)->where('is_active', true)->pluck('id')->all());
        $out = [];
        $seen = [];
        foreach (array_filter($rows, 'is_array') as $row) {
            $equipmentId = (int) ($row['equipment_id'] ?? 0);
            if (!isset($existing[$equipmentId])) {
                throw ValidationException::withMessages(['rows' => 'Seçilen tüplerden biri bu lokasyonda bulunamadı ya da pasif.']);
            }
            if (isset($seen[$equipmentId])) {
                throw ValidationException::withMessages(['rows' => 'Aynı tüp iki satırda seçilmiş.']);
            }
            $seen[$equipmentId] = true;
            $out[] = ['equipment_id' => $equipmentId, 'update_place' => !empty($row['update_place'])] + $this->cleanRow($row);
        }

        return $out;
    }

    // Satırın formdaki değerleri: yer, no, etiket, rapordaki tip metni, kapasite, dolum, sonuç, bulgular, özellikler.
    private function cleanRow(array $row): array
    {
        $text = fn ($value, int $max) => mb_substr(trim((string) $value), 0, $max) ?: null;

        return [
            'status' => in_array($row['status'] ?? null, ['uygun', 'uygun_degil'], true) ? $row['status'] : 'belirtilmemis',
            'code' => $text($row['code'] ?? null, 100),
            'place' => $text($row['place'] ?? null, 255),
            'variant' => $text($row['variant'] ?? null, 50),
            'type_text' => $text($row['type_text'] ?? null, 100),
            'capacity' => $text($row['capacity'] ?? null, 50),
            'fill_date' => $text($row['fill_date'] ?? null, 50),
            'brand' => $text($row['brand'] ?? null, 100),
            'model' => $text($row['model'] ?? null, 100),
            'serial_no' => $text($row['serial_no'] ?? null, 100),
            'properties' => collect((array) ($row['properties'] ?? []))
                ->filter(fn ($value, $label) => is_scalar($value) && trim((string) $value) !== '' && trim((string) $label) !== '')
                ->mapWithKeys(fn ($value, $label) => [mb_substr(trim((string) $label), 0, 255) => mb_substr(trim((string) $value), 0, 1000)])
                ->all(),
            'findings' => $this->cleanTexts((array) ($row['findings'] ?? [])),
        ];
    }

    // PkBulkReportService::cleanSystems kopyası.
    private function cleanSystems(array $systems): array
    {
        return collect($systems)
            ->filter(fn ($system) => is_array($system) && trim((string) ($system['name'] ?? '')) !== '')
            ->map(fn (array $system) => [
                'name' => mb_substr(trim((string) $system['name']), 0, 255),
                'status' => in_array($system['status'] ?? null, ['uygun', 'uygun_degil'], true) ? $system['status'] : null,
                'findings' => $this->cleanTexts((array) ($system['findings'] ?? [])),
                'equipment_count' => max(0, (int) ($system['equipment_count'] ?? 0)),
            ])
            ->values()->all();
    }

    private function cleanTexts(array $texts): array
    {
        return collect($texts)->map(fn ($text) => trim((string) $text))->filter()->unique()->values()->all();
    }
}
