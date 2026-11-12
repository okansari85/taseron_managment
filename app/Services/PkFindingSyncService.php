<?php

namespace App\Services;

use App\Models\PkEquipment;
use App\Models\PkFinding;
use App\Models\PkFindingEvent;
use App\Models\PkFindingSource;
use App\Models\PkInspection;
use App\Models\PkInstallation;
use App\Models\PkInstallationReport;
use App\Models\PkInstallationReportComponent;
use App\Models\PkInstallationReportSystem;
use Illuminate\Support\Facades\DB;

/**
 * Bulgu Takip (2026-11-12): bir raporun bulgularını takip kayıtlarıyla eşitler. Kaynak 'inspection' (ekipman kontrolü) ya da
 * 'installation_report' (tesisat raporu: genel bulgular, sistem bulguları, bileşen bulguları). Rapor kaydedilip işlem bitince
 * PkFindingSourceObserver çağırır; eski raporlar için pk:findings-import.
 *
 * - Aynı konuda (ekipman / tesisat geneli / sistem, bileşen) aynı metinli açık bulgu varsa yeni kayıt açılmaz, rapor ona bağlanır.
 * - Rapordan çıkan (rapor düzenlendi / silindi) bulgu başka raporda yoksa: üzerinde işlem yoksa silinir, varsa kalır.
 * - Yeni rapor (created) bir konu için uygunsa o konunun eski açık bulgularına kapatma önerisi düşer; kullanıcı onaylar.
 * - Tesisat raporundan ekipmanlara yazılan kontrol kayıtları atlanır: bulguları tesisat raporunda zaten var.
 */
class PkFindingSyncService
{
    /** @param list<string>|null $onlySubjects yalnız bu konular (aktarmada: raporun en son olduğu konular) */
    public function sync(string $type, int $id, bool $created = false, ?array $onlySubjects = null): void
    {
        [$source, $items] = $type === 'inspection' ? $this->inspectionItems($id) : $this->installationItems($id);
        if ($onlySubjects !== null) {
            $items = array_values(array_filter($items, fn (array $item) => in_array($item['subject'], $onlySubjects, true)));
        }

        DB::transaction(function () use ($type, $id, $created, $source, $items) {
            $links = PkFindingSource::query()->where('source_type', $type)->where('source_id', $id)->get()->keyBy('pk_finding_id');
            $linked = PkFinding::withoutGlobalScopes()->whereKey($links->keys())->get()->keyBy('id');
            $keep = [];
            foreach ($items as $item) {
                $existing = $linked->first(fn (PkFinding $finding) => $this->same($finding, $item));
                if ($existing) {
                    $keep[] = $existing->id;
                    continue;
                }
                $keep[] = $this->attach($type, $id, $source, $item)->id;
            }
            foreach ($links as $findingId => $link) {
                if (!in_array($findingId, $keep, true)) {
                    $link->delete();
                    $this->detached($linked[$findingId] ?? null);
                }
            }
            if ($created && $source) {
                $this->suggestClose($type, $id, $source, $keep);
            }
        });
    }

    // --- Raporun bulguları ---

    /** @return array{0: ?array, 1: list<array>} */
    private function inspectionItems(int $id): array
    {
        $inspection = PkInspection::withoutGlobalScopes()->find($id);
        $fromInstallation = DB::table('pk_installation_report_inspections')->where('pk_inspection_id', $id)->exists();
        $equipment = $inspection ? PkEquipment::withoutGlobalScopes()->find($inspection->pk_equipment_id) : null;
        if (!$inspection || !$equipment || $fromInstallation) {
            return [null, []];
        }
        $subject = 'e:' . $equipment->id;
        $base = [
            'subject' => $subject, 'pk_equipment_id' => $equipment->id, 'pk_installation_id' => null, 'pk_installation_system_id' => null,
            'location_id' => $equipment->location_id, 'location_business_entity_id' => $equipment->location_business_entity_id,
        ];
        $source = ['tenant_id' => $inspection->tenant_id, 'control_date' => $inspection->control_date?->format('Y-m-d'), 'statuses' => [$subject => $inspection->status]];

        return [$source, $this->items($base, $this->texts($inspection->findings))];
    }

    /** @return array{0: ?array, 1: list<array>} */
    private function installationItems(int $id): array
    {
        $report = PkInstallationReport::withoutGlobalScopes()->find($id);
        $installation = $report ? PkInstallation::withoutGlobalScopes()->find($report->pk_installation_id) : null;
        if (!$report || !$installation) {
            return [null, []];
        }
        $base = [
            'pk_equipment_id' => null, 'pk_installation_id' => $installation->id,
            'location_id' => $installation->location_id, 'location_business_entity_id' => $installation->location_business_entity_id,
        ];
        $results = PkInstallationReportSystem::query()->where('pk_installation_report_id', $report->id)->get();
        $components = PkInstallationReportComponent::query()->whereIn('pk_installation_report_system_id', $results->pluck('id'))->get()
            ->groupBy('pk_installation_report_system_id');

        $items = [];
        $statuses = [];
        $inSystems = [];
        foreach ($results as $result) {
            $subject = 's:' . $result->pk_installation_system_id;
            $statuses[$subject] = $result->status;
            $system = $base + ['subject' => $subject, 'pk_installation_system_id' => $result->pk_installation_system_id];
            $componentTexts = [];
            foreach ($components->get($result->id, collect()) as $row) {
                foreach ((array) $row->components as $component) {
                    $texts = $this->texts($component['findings'] ?? []);
                    $componentTexts = [...$componentTexts, ...$texts];
                    $items = [...$items, ...$this->items($system, $texts, $this->componentLabel((array) $component))];
                }
            }
            // Bileşende yazan bulgu sistemde tekrar yazılmaz.
            $texts = $this->texts($result->findings);
            $inSystems = [...$inSystems, ...$texts, ...$componentTexts];
            $items = [...$items, ...$this->items($system, array_diff($texts, $componentTexts))];
        }
        // Genel bulgular: raporun bulgularından sistemlere düşmeyenler (rapor satırı sistemlerinkini de içerir).
        $subject = 'g:' . $installation->id;
        $statuses[$subject] = $report->status;
        $items = [...$items, ...$this->items($base + ['subject' => $subject, 'pk_installation_system_id' => null], array_diff($this->texts($report->findings), $inSystems))];
        $source = ['tenant_id' => $report->tenant_id, 'control_date' => $report->control_date?->format('Y-m-d'), 'statuses' => $statuses];

        return [$source, $items];
    }

    /** @return list<array> aynı konu + metin bir kez */
    private function items(array $base, array $texts, ?string $component = null): array
    {
        $items = [];
        foreach ($texts as $text) {
            $key = self::key($text, $component);
            $items[$base['subject'] . '|' . $key] = $base + ['component' => $component, 'text' => $text, 'text_key' => $key];
        }

        return array_values($items);
    }

    // Bulgular metin listesi (eski kayıtlarda {description: ...} nesnesi olabilir); boşluklar sadeleşir.
    private function texts(mixed $findings): array
    {
        $texts = array_map(
            fn ($finding) => trim((string) preg_replace('/\s+/u', ' ', is_array($finding) ? (string) ($finding['description'] ?? '') : (string) $finding)),
            is_array($findings) ? $findings : []
        );

        return array_values(array_unique(array_filter($texts, fn (string $text) => $text !== '')));
    }

    private function componentLabel(array $component): ?string
    {
        $label = trim(implode(' ', array_filter([trim((string) ($component['equipment_name'] ?? '')), trim((string) ($component['code'] ?? ''))])));

        return mb_substr($label ?: trim((string) ($component['place'] ?? '')), 0, 255) ?: null;
    }

    public static function key(string $text, ?string $component): string
    {
        return sha1(mb_strtolower($text) . '|' . mb_strtolower((string) $component));
    }

    // --- Kayıtlar ---

    private function same(PkFinding $finding, array $item): bool
    {
        return $finding->text_key === $item['text_key'] && (int) $finding->pk_equipment_id === (int) $item['pk_equipment_id']
            && (int) $finding->pk_installation_id === (int) $item['pk_installation_id']
            && (int) $finding->pk_installation_system_id === (int) $item['pk_installation_system_id'];
    }

    private function subjectQuery(int $tenantId, array $item)
    {
        $query = PkFinding::withoutGlobalScopes()->where('tenant_id', $tenantId);
        foreach (['pk_equipment_id', 'pk_installation_id', 'pk_installation_system_id'] as $column) {
            $item[$column] ? $query->where($column, $item[$column]) : $query->whereNull($column);
        }

        return $query;
    }

    private function attach(string $type, int $id, array $source, array $item): PkFinding
    {
        $date = $source['control_date'];
        $open = $this->subjectQuery($source['tenant_id'], $item)->where('text_key', $item['text_key'])->where('status', '!=', 'kapandi')->first();
        if ($open) {
            if ($date && (!$open->last_seen_date || $open->last_seen_date->format('Y-m-d') < $date)) {
                // Daha yeni raporda yine geçiyor: eski kapatma önerisi geçersiz.
                $open->update(['last_seen_date' => $date, 'close_suggested_by' => null, 'close_suggested_date' => null]);
            }
            $this->event($open, 'tekrar_goruldu', meta: ['source_type' => $type, 'source_id' => $id, 'control_date' => $date]);
        } else {
            $open = PkFinding::withoutGlobalScopes()->create(collect($item)->except('subject')->all() + [
                'tenant_id' => $source['tenant_id'], 'status' => 'acik', 'last_seen_date' => $date,
            ]);
            $this->event($open, 'olusturuldu', meta: ['source_type' => $type, 'source_id' => $id, 'control_date' => $date]);
        }
        PkFindingSource::query()->create(['pk_finding_id' => $open->id, 'source_type' => $type, 'source_id' => $id, 'control_date' => $date]);

        return $open;
    }

    // Raporu kalmayan bulgu: üzerinde işlem yoksa silinir, varsa kalır ve geçmişe yazılır.
    private function detached(?PkFinding $finding): void
    {
        if (!$finding || PkFindingSource::query()->where('pk_finding_id', $finding->id)->exists()) {
            return;
        }
        $untouched = $finding->status === 'acik' && !$finding->work && !$finding->responsible_user_id && !$finding->due_date
            && !$finding->recheck_date && !PkFindingEvent::query()->where('pk_finding_id', $finding->id)->whereIn('type', PkFindingEvent::USER_WORK)->exists();
        if ($untouched) {
            $finding->delete();
        } else {
            $this->event($finding, 'rapordan_cikarildi');
        }
    }

    // Yeni rapor bir konu için uygunsa o konunun bu raporda geçmeyen, daha eski açık bulgularına kapatma önerisi.
    private function suggestClose(string $type, int $id, array $source, array $keep): void
    {
        $date = $source['control_date'];
        foreach ($source['statuses'] as $subject => $status) {
            if ($status !== 'uygun') {
                continue;
            }
            [$kind, $subjectId] = explode(':', $subject);
            $column = ['e' => 'pk_equipment_id', 'g' => 'pk_installation_id', 's' => 'pk_installation_system_id'][$kind];
            $query = PkFinding::withoutGlobalScopes()->where('tenant_id', $source['tenant_id'])->where($column, $subjectId)
                ->where('status', '!=', 'kapandi')->whereNotIn('id', $keep)
                // Aynı kayıt bir istekte iki kez kaydedilebilir (oluşturulup güncellenir): öneri bir kez.
                ->where(fn ($q) => $q->whereNull('close_suggested_by')->orWhere('close_suggested_by', '!=', "{$type}:{$id}"))
                ->when($kind === 'g', fn ($q) => $q->whereNull('pk_installation_system_id'))
                ->when($date, fn ($q) => $q->where(fn ($inner) => $inner->whereNull('last_seen_date')->orWhere('last_seen_date', '<=', $date)));
            foreach ($query->get() as $finding) {
                $finding->update(['close_suggested_by' => "{$type}:{$id}", 'close_suggested_date' => $date]);
                $this->event($finding, 'oneri', meta: ['source_type' => $type, 'source_id' => $id, 'control_date' => $date]);
            }
        }
    }

    private function event(PkFinding $finding, string $type, ?string $note = null, ?array $meta = null): void
    {
        PkFindingEvent::query()->create(['pk_finding_id' => $finding->id, 'type' => $type, 'note' => $note, 'meta' => $meta]);
    }
}
