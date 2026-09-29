<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\PeriodicEquipmentSpec;
use App\Models\PeriodicEquipmentSpecRequest;
use App\Models\PkEquipment;
use App\Models\PkEquipmentPropertyValue;
use App\Models\PkInspection;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Ekipmanın rapordan okunan teknik özellikleri, türün teknik özellik kataloğuna göre (firma ne yazarsa yazsın
 * "Kapasite" / "Kaldırma Kapasitesi (kg)" tek özellik). Her raporun değerleri, elle düzeltmeler ve silmeler ayrı kayıt
 * olarak saklanır; rapordaki orijinal başlık ve değer de tutulur. Güncel değer: kabul edilmiş kayıtların en son
 * kararlaştırılanı (kayıt sırası).
 *
 * Otomatik hiçbir şey yok - rapordaki her değişiklik kullanıcının açık kararıyla uygulanır:
 * - Yeni özellik: "add" denirse eklenir (pencerede işaretli liste).
 * - Farklı değer: "report" denirse rapordaki geçerli olur, yoksa mevcut kalır (elle düzeltilmişte varsayılan "mevcut kalsın").
 * - Silinmiş özellik raporda tekrar gelirse: "add" denirse geri eklenir (varsayılan eklenmez).
 * - "NU", "-" gibi boş değerler gerçek bir değeri ezmez (yalnızca geçmişte; başka değer yoksa gösterilir).
 * - Eski tarihli rapor (güncel değerin geldiği rapordan eski) farklı değeri değiştirmez, yalnızca geçmişe eklenir.
 * - Raporda olmayan özellik: önceki değer kalır.
 * - Katalog dışı değer: yoksayılır, mevcut bir özelliğe bağlanır, yetkiliyse kataloğa eklenir, değilse yetkiliye talep gider.
 */
class PkEquipmentPropertyService
{
    private const EMPTY_WORDS = ['nu', 'yok', 'belirtilmemis', 'okunamadi', 'na', 'bilinmiyor'];

    public function __construct(private TenantContext $tenantContext, private PeriodicEquipmentSpecCatalog $catalog)
    {
    }

    /**
     * Rapordaki değerleri katalog özelliklerine eşler: önce Gemini'nin katalog anahtarıyla verdiği (equipment_specs),
     * sonra rapordaki başlıkların katalog adı / diğer adlarla eşleşmesi. Etiket alanı (örn. Tahrik türü) ayrı tutulur.
     *
     * @return array{items: array<int, array>, unmapped: array<int, array{id: string, label: string, value: string}>}
     */
    public function resolve(PkEquipment $equipment, array $semantic, array $equipmentSummary): array
    {
        $specs = $this->catalog->specsFor($equipment->type);
        $byKey = $specs->keyBy('key');
        $items = [];
        $usedLabels = [];

        foreach ((array) ($semantic['extracted_data']['equipment_specs'] ?? []) as $row) {
            $spec = $byKey[(string) ($row['key'] ?? '')] ?? null;
            $value = trim((string) ($row['value'] ?? ''));
            $rawValue = trim((string) ($row['raw_value'] ?? '')) ?: $value;
            if (!$spec || isset($items[$spec->key]) || $rawValue === '') {
                continue;
            }
            $items[$spec->key] = $this->item($spec, $value !== '' ? $value : $rawValue, $row['raw_label'] ?? null, $rawValue);
            if (filled($row['raw_label'] ?? null)) {
                $usedLabels[$this->catalog->normalize((string) $row['raw_label'])] = true;
            }
        }

        $tagLabel = $this->catalog->normalize((string) $equipment->type?->variant_label);
        $unmapped = [];
        foreach ($this->rawPairs($equipmentSummary) as [$label, $value]) {
            $normalized = $this->catalog->normalize($label);
            if ($normalized === '' || isset($usedLabels[$normalized])) {
                continue;
            }
            $usedLabels[$normalized] = true;
            if ($spec = $this->catalog->match($label, $specs)) {
                $items[$spec->key] ??= $this->item($spec, $value, $label, $value);
            } elseif ($tagLabel === '' || $normalized !== $tagLabel) {
                $unmapped[] = ['id' => $normalized, 'label' => $label, 'value' => $value];
            }
        }

        return ['items' => array_values($items), 'unmapped' => $unmapped];
    }

    /**
     * Kayıttan önce (rapor penceresi): kullanıcıya sorulacaklar.
     * changes: farklı değer (report | keep), new: yeni özellik (add | skip), restore: silinmişken tekrar gelen (add | skip),
     * unmapped: katalog dışı (ignore | map | add | request).
     */
    public function preview(PkEquipment $equipment, array $semantic, array $equipmentSummary, ?string $controlDate, ?User $user): array
    {
        $resolved = $this->resolve($equipment, $semantic, $equipmentSummary);
        $state = $this->state($equipment->id);
        $date = $controlDate ? Carbon::parse($controlDate)->startOfDay() : now();
        $result = ['changes' => [], 'new' => [], 'restore' => [], 'same' => 0];

        foreach ($resolved['items'] as $item) {
            $key = $item['key'];
            $empty = $this->isEmpty($item['value']);
            $base = $item;

            if (isset($state['removed'][$key])) {
                if (!$empty) {
                    $result['restore'][] = $base + ['default' => 'skip'];
                }
            } elseif (!isset($state['seen'][$key])) {
                $result['new'][] = $base + ['is_empty' => $empty, 'default' => 'add'];
            } elseif ($empty) {
                continue;
            } elseif (!$existing = $state['current'][$key] ?? null) {
                // Şimdiye kadar yalnızca boş değer ("-", "NU") vardı; rapor gerçek bir değer getirdi.
                $result['changes'][] = $base + ['current_value' => $state['display'][$key]->value ?? '—', 'current_source' => 'report', 'current_report_no' => null, 'report_value' => $item['value'], 'default' => 'report'];
            } elseif ($this->token($existing->value) === $this->token($item['value'])) {
                $result['same']++;
            } elseif (!$this->olderReport($existing, $date)) {
                $result['changes'][] = $base + [
                    'current_value' => $existing->value,
                    'current_source' => $existing->source,
                    'current_report_no' => $existing->report_no,
                    'report_value' => $item['value'],
                    // Elle düzeltilmiş değer, sen değiştirene kadar kalır.
                    'default' => $existing->source === 'manual' ? 'keep' : 'report',
                ];
            }
        }

        return $result + [
            'unmapped' => $resolved['unmapped'],
            // "Şu özellikle aynı" seçimi için türün kataloğu.
            'specs' => $this->catalog->specsFor($equipment->type)->map(fn ($spec) => ['key' => $spec->key, 'name' => $spec->name, 'unit' => $spec->unit])->values(),
            'can_edit_catalog' => $this->catalog->canEdit($user),
        ];
    }

    /**
     * Kayıtta: rapordaki her değer ayrı kayıt; yalnızca kullanıcının açıkça onayladıkları ("report" / "add") geçerli olur.
     * $unmappedDecisions: katalog dışı değerler için [id => ['action' => ignore|map|add|request, 'key', 'name', 'unit']].
     */
    public function recordReport(PkEquipment $equipment, PkInspection $inspection, array $analysis, array $decisions, array $unmappedDecisions, User $user): void
    {
        $resolved = $this->resolve($equipment, (array) ($analysis['semantic'] ?? []), (array) ($analysis['summary']['equipment'] ?? []));
        $reportNo = $analysis['summary']['report_no'] ?? null;
        $effective = Carbon::parse($inspection->control_date)->startOfDay();
        $items = $resolved['items'];

        // Katalog dışı kararlar: bağlanan / kataloğa eklenen değer kullanıcının açık onayıdır.
        $specs = $this->catalog->specsFor($equipment->type)->keyBy('key');
        $canEdit = $this->catalog->canEdit($user);
        foreach ($resolved['unmapped'] as $row) {
            $decision = (array) ($unmappedDecisions[$row['id']] ?? []);
            $action = $decision['action'] ?? 'ignore';
            if ($action === 'map' && ($spec = $specs[(string) ($decision['key'] ?? '')] ?? null)) {
                if ($canEdit) {
                    $this->catalog->addAlias($spec, $row['label'], $user);
                }
            } elseif (in_array($action, ['add', 'request'], true) && $canEdit) {
                $spec = $this->catalog->addSpec($equipment->type, (string) ($decision['name'] ?? '') ?: $row['label'], $decision['unit'] ?? null, $row['label'], $user);
                $specs[$spec->key] = $spec;
            } elseif (in_array($action, ['add', 'request'], true)) {
                $this->requestSpec($equipment, $inspection, $row, $decision, $reportNo, $effective, $user);
                continue;
            } else {
                continue;
            }
            $items = array_values(array_filter($items, fn ($item) => $item['key'] !== $spec->key));
            $items[] = $this->item($spec, $row['value'], $row['label'], $row['value']);
            $decisions[$spec->key] = 'accept';
        }

        $state = $this->state($equipment->id);
        foreach ($items as $item) {
            $key = $item['key'];
            $empty = $this->isEmpty($item['value']);
            $decision = $decisions[$key] ?? null;
            $existing = $state['current'][$key] ?? null;

            $accepted = match (true) {
                isset($state['removed'][$key]) => !$empty && in_array($decision, ['add', 'accept'], true),
                !isset($state['seen'][$key]) => in_array($decision, ['add', 'accept'], true),
                // Boş değer ya da aynı değer: hiçbir şeyi değiştirmez, geçmişe eklenir.
                $empty, $existing && $this->token($existing->value) === $this->token($item['value']) => true,
                $existing && $this->olderReport($existing, $effective) && $decision !== 'accept' => false,
                default => in_array($decision, ['report', 'accept'], true),
            };

            $this->create($equipment, [
                'pk_inspection_id' => $inspection->id,
                'field' => $item['field'],
                'field_key' => $key,
                'value' => $item['value'],
                'raw_field' => $item['raw_field'],
                'raw_value' => $item['raw_value'],
                'is_empty' => $empty,
                'accepted' => $accepted,
                'source' => 'report',
                'report_no' => $reportNo,
                'effective_at' => $effective,
            ], $user);
        }
    }

    /**
     * Çok ekipmanlı rapordan (tesisat raporu) bir ekipmanın tablo satırındaki özellikler: yalnızca katalogdaki özellikle
     * (ad ya da diğer adlar) eşleşenler yazılır, katalog dışı olanlar yok sayılır. Yeni ekipmanda değerler geçerlidir;
     * mevcut ekipmanda yalnızca değeri olmayan özellik geçerli olur, var olan değer ezilmez (geçmişe eklenir).
     */
    public function recordTableRow(PkEquipment $equipment, PkInspection $inspection, array $properties, ?string $reportNo, bool $newEquipment, User $user): void
    {
        $specs = $this->catalog->specsFor($equipment->type);
        $state = $this->state($equipment->id);
        $effective = Carbon::parse($inspection->control_date)->startOfDay();
        $done = [];
        foreach ($properties as $label => $value) {
            $value = trim((string) $value);
            $spec = $value !== '' ? $this->catalog->match((string) $label, $specs) : null;
            if (!$spec || isset($done[$spec->key])) {
                continue;
            }
            $done[$spec->key] = true;
            $item = $this->item($spec, $value, (string) $label, $value);
            $empty = $this->isEmpty($item['value']);
            $this->create($equipment, [
                'pk_inspection_id' => $inspection->id,
                'field' => $item['field'],
                'field_key' => $item['key'],
                'value' => $item['value'],
                'raw_field' => $item['raw_field'],
                'raw_value' => $item['raw_value'],
                'is_empty' => $empty,
                'accepted' => !$empty && !isset($state['removed'][$item['key']]) && ($newEquipment || !isset($state['current'][$item['key']])),
                'source' => 'report',
                'report_no' => $reportNo,
                'effective_at' => $effective,
            ], $user);
        }
    }

    // Profilde elle düzeltme: katalogdaki, raporlardan gelmiş ve silinmemiş bir özellik.
    public function correct(PkEquipment $equipment, string $key, string $value, User $user): void
    {
        $spec = $this->specOrFail($equipment, $key);
        $this->visibleLast($equipment, $key);
        $value = trim($value);
        if ($value === '') {
            throw ValidationException::withMessages(['value' => 'Değer boş olamaz.']);
        }

        $this->create($equipment, ['field' => $spec->name, 'field_key' => $key, 'value' => $this->catalog->plainValue($value, $spec->unit), 'is_empty' => $this->isEmpty($value), 'accepted' => true, 'source' => 'manual', 'effective_at' => now()], $user);
    }

    // Silme: özellik listeden kalkar, geçmiş korunur; sonraki raporda gelirse eklensin mi diye sorulur.
    public function remove(PkEquipment $equipment, string $key, User $user): void
    {
        $spec = $this->specOrFail($equipment, $key);
        $this->visibleLast($equipment, $key);

        $this->create($equipment, ['field' => $spec->name, 'field_key' => $key, 'value' => '', 'is_empty' => true, 'accepted' => true, 'is_removed' => true, 'source' => 'manual', 'effective_at' => now()], $user);
    }

    // Profil: katalog sırasıyla silinmemiş her özelliğin güncel değeri + geçmişi; onay bekleyen katalog talepleri ayrı.
    public function present(PkEquipment $equipment): array
    {
        $specs = $this->catalog->specsFor($equipment->type)->values();
        $order = $specs->pluck('key')->flip();
        $specs = $specs->keyBy('key');
        $entries = PkEquipmentPropertyValue::query()->where('pk_equipment_id', $equipment->id)->with('creator:id,name')->orderBy('id')->get();
        $state = $this->stateFrom($entries);

        return $entries->groupBy('field_key')
            ->filter(fn (Collection $rows, string $key) => isset($specs[$key]) && !isset($state['removed'][$key]))
            ->sortBy(fn (Collection $rows, string $key) => $order[$key])
            ->map(function (Collection $rows, string $key) use ($state, $specs) {
                $value = $state['display'][$key] ?? null;
                $values = $rows->reject(fn ($row) => $row->is_removed);

                return [
                    'key' => $key,
                    'field' => $specs[$key]->name,
                    'unit' => $specs[$key]->unit,
                    'value' => $value?->value,
                    'source' => $value?->source,
                    'report_no' => $value?->report_no,
                    'raw_field' => $value?->raw_field,
                    'raw_value' => $value?->raw_value,
                    'effective_at' => $value?->effective_at?->toDateString(),
                    // Raporlar arasında (boş olmayan) farklı değer görüldü mü.
                    'changed' => $values->reject(fn ($row) => $row->is_empty)->map(fn ($row) => $this->token($row->value))->unique()->count() > 1,
                    'history' => $rows
                        ->sortBy([['effective_at', 'desc'], ['id', 'desc']])
                        ->values()
                        ->map(fn (PkEquipmentPropertyValue $row) => [
                            'id' => $row->id,
                            'value' => $row->is_removed ? 'Silindi' : $row->value,
                            'raw_field' => $row->raw_field,
                            'raw_value' => $row->raw_value,
                            'source' => $row->source,
                            'accepted' => $row->accepted,
                            'is_empty' => $row->is_empty,
                            'is_removed' => $row->is_removed,
                            'current' => $value?->id === $row->id,
                            'report_no' => $row->report_no,
                            'effective_at' => $row->effective_at?->toDateString(),
                            'created_by' => $row->creator?->name,
                        ]),
                ];
            })->values()->all();
    }

    // Profil: bu ekipman için onay bekleyen katalog talepleri.
    public function pendingRequests(PkEquipment $equipment): array
    {
        return PeriodicEquipmentSpecRequest::query()
            ->where('tenant_id', $equipment->tenant_id)
            ->where('pk_equipment_id', $equipment->id)
            ->where('status', 'pending')
            ->orderBy('id')
            ->get()
            ->map(fn (PeriodicEquipmentSpecRequest $request) => ['id' => $request->id, 'name' => $request->name, 'unit' => $request->unit, 'raw_label' => $request->raw_label, 'raw_value' => $request->raw_value])
            ->all();
    }

    /**
     * Kaydedilmiş raporun katalogda karşılığı olmayan özellikleri (güncel kataloğa göre) ve bu rapordan açılmış talepler.
     * Kataloğa eklenmesi, rapor kaydedildikten sonra yapay zeka özetinden talep edilir.
     */
    public function unmappedOf(PkEquipment $equipment, PkInspection $inspection): array
    {
        $analysis = (array) $inspection->analysis;
        $unmapped = $this->resolve($equipment, (array) ($analysis['semantic'] ?? []), (array) ($analysis['summary']['equipment'] ?? []))['unmapped'];
        $requests = PeriodicEquipmentSpecRequest::query()
            ->with('spec:id,name,unit')
            ->where('tenant_id', $equipment->tenant_id)
            ->where('pk_inspection_id', $inspection->id)
            ->orderBy('id')
            ->get()
            ->keyBy(fn (PeriodicEquipmentSpecRequest $request) => $this->catalog->normalize((string) $request->raw_label));

        $rows = collect($unmapped)->map(fn (array $row) => $row + ['request' => $this->presentRequest($requests->pull($row['id']))]);
        // Onaylanıp kataloğa girenler artık eşleşiyor; talebin sonucu yine gösterilir.
        foreach ($requests as $id => $request) {
            $rows->push(['id' => $id, 'label' => $request->raw_label, 'value' => $request->raw_value, 'request' => $this->presentRequest($request)]);
        }

        return $rows->values()->all();
    }

    // Yapay zeka özetinden: kaydedilmiş raporun katalog dışı bir özelliği için yetkiliye talep.
    public function requestFromInspection(PkEquipment $equipment, PkInspection $inspection, string $id, string $name, ?string $unit, User $user): void
    {
        $row = collect($this->unmappedOf($equipment, $inspection))->firstWhere('id', $id);
        if (!$row) {
            throw ValidationException::withMessages(['id' => 'Bu özellik raporda bulunamadı ya da artık katalogda var.']);
        }
        if (in_array($row['request']['status'] ?? null, ['pending', 'approved'], true)) {
            throw ValidationException::withMessages(['id' => 'Bu özellik için zaten talep var.']);
        }
        $reportNo = $inspection->report_no ?: ($inspection->analysis['summary']['report_no'] ?? null);
        $this->requestSpec($equipment, $inspection, $row, ['name' => $name, 'unit' => $unit], $reportNo, Carbon::parse($inspection->control_date)->startOfDay(), $user);
    }

    private function presentRequest(?PeriodicEquipmentSpecRequest $request): ?array
    {
        return $request ? [
            'id' => $request->id,
            'status' => $request->status,
            'name' => $request->name,
            'unit' => $request->unit,
            'note' => $request->decision_note,
            'spec' => $request->spec ? ['name' => $request->spec->name, 'unit' => $request->spec->unit] : null,
        ] : null;
    }

    // Uzman: katalog dışı özellik için yetkiliye talep; değer "onay bekliyor" olarak saklanır.
    private function requestSpec(PkEquipment $equipment, PkInspection $inspection, array $row, array $decision, ?string $reportNo, Carbon $effective, User $user): void
    {
        $name = trim((string) ($decision['name'] ?? '')) ?: $row['label'];
        $request = PeriodicEquipmentSpecRequest::create([
            'tenant_id' => $this->tenantContext->id(),
            'equipment_type_id' => $equipment->equipment_type_id,
            'pk_equipment_id' => $equipment->id,
            'pk_inspection_id' => $inspection->id,
            'name' => $name,
            'unit' => filled($decision['unit'] ?? null) ? trim($decision['unit']) : null,
            'raw_label' => $row['label'],
            'raw_value' => $row['value'],
            'requested_by' => $user->id,
        ]);

        $this->create($equipment, [
            'pk_inspection_id' => $inspection->id,
            'spec_request_id' => $request->id,
            'field' => $name,
            'field_key' => 'talep_' . $request->id,
            'value' => $row['value'],
            'raw_field' => $row['label'],
            'raw_value' => $row['value'],
            'is_empty' => $this->isEmpty($row['value']),
            'accepted' => false,
            'source' => 'report',
            'report_no' => $reportNo,
            'effective_at' => $effective,
        ], $user);
    }

    private function item(PeriodicEquipmentSpec $spec, string $value, ?string $rawField, ?string $rawValue): array
    {
        return [
            'key' => $spec->key,
            'field' => $spec->name,
            'unit' => $spec->unit,
            'value' => $this->catalog->plainValue($value, $spec->unit),
            'raw_field' => filled($rawField) ? trim($rawField) : null,
            'raw_value' => filled($rawValue) ? trim($rawValue) : null,
        ];
    }

    // Rapordaki tek örnekli ekipmanın kimliği + attributes: [başlık, değer], raporda yazdığı sırayla.
    private function rawPairs(array $equipmentSummary): array
    {
        $pairs = [];
        foreach ($equipmentSummary as $item) {
            if (filled($item['identity_field'] ?? null) && filled($item['identity'] ?? null)) {
                $pairs[] = [(string) $item['identity_field'], trim((string) $item['identity'])];
            }
            foreach ((array) ($item['attributes'] ?? []) as $attribute) {
                if (filled($attribute['field'] ?? null) && filled($attribute['value'] ?? null)) {
                    $pairs[] = [(string) $attribute['field'], trim((string) $attribute['value'])];
                }
            }
        }

        return $pairs;
    }

    private function specOrFail(PkEquipment $equipment, string $key): PeriodicEquipmentSpec
    {
        $spec = $this->catalog->specsFor($equipment->type)->firstWhere('key', $key);
        if (!$spec) {
            throw ValidationException::withMessages(['key' => 'Özellik katalogda yok.']);
        }

        return $spec;
    }

    private function create(PkEquipment $equipment, array $attributes, User $user): void
    {
        PkEquipmentPropertyValue::create($attributes + [
            'tenant_id' => $this->tenantContext->id(),
            'pk_equipment_id' => $equipment->id,
            'created_by' => $user->id,
        ]);
    }

    private function visibleLast(PkEquipment $equipment, string $key): PkEquipmentPropertyValue
    {
        $state = $this->state($equipment->id);
        $last = PkEquipmentPropertyValue::query()->where('pk_equipment_id', $equipment->id)->where('field_key', $key)->where('is_removed', false)->latest('id')->first();
        if (!$last || isset($state['removed'][$key])) {
            throw ValidationException::withMessages(['key' => 'Özellik bulunamadı.']);
        }

        return $last;
    }

    // Güncel değer bir rapordan geliyorsa ve yeni rapor ondan eski tarihliyse.
    private function olderReport(PkEquipmentPropertyValue $current, Carbon $date): bool
    {
        return $current->source === 'report' && $date->lt($current->effective_at);
    }

    // Kimliği olmayan taslak ekipman (rapordan yeni ekipman, henüz kaydedilmedi): hiç özellik yok.
    private function state(?int $equipmentId): array
    {
        if ($equipmentId === null) {
            return $this->stateFrom(collect());
        }

        return $this->stateFrom(PkEquipmentPropertyValue::query()->where('pk_equipment_id', $equipmentId)->orderBy('id')->get());
    }

    /**
     * seen: şimdiye kadar görülen özellikler; removed: son kararı silme olanlar;
     * current: boş olmayan güncel değer; display: gösterilecek değer (gerçek değer yoksa son boş değer).
     * Güncel değer kayıt sırasıyla belirlenir; aynı değeri birden fazla kayıt taşıyorsa kaynak olarak en yeni tarihlisi alınır.
     */
    private function stateFrom(Collection $entries): array
    {
        $state = ['seen' => [], 'removed' => [], 'current' => [], 'display' => []];
        foreach ($entries->groupBy('field_key') as $key => $rows) {
            $state['seen'][$key] = true;
            $accepted = $rows->where('accepted', true)->sortBy('id');
            // Hiç onaylanmamış (raporda gelip eklenmesi istenmemiş) özellik silinmiş sayılır: gösterilmez, tekrar gelirse sorulur.
            if ($accepted->isEmpty()) {
                $state['removed'][$key] = $rows->last();
                continue;
            }
            $decisive = $accepted->filter(fn ($row) => $row->is_removed || !$row->is_empty)->last();
            if ($decisive?->is_removed) {
                $state['removed'][$key] = $decisive;
                continue;
            }
            // Gerçek değer hiç yoksa son boş değer ("-", "NU") gösterilir.
            $value = $decisive ?? $accepted->last();
            $same = $accepted
                ->filter(fn ($row) => !$row->is_removed && $row->is_empty === $value->is_empty && $this->token($row->value) === $this->token($value->value))
                ->sortBy([['effective_at', 'desc'], ['id', 'desc']])
                ->first();
            $state['display'][$key] = $same;
            if (!$value->is_empty) {
                $state['current'][$key] = $same;
            }
        }

        return $state;
    }

    // "-", "NU", "NU / NU", "Yok", "N/A": değer yok.
    private function isEmpty(string $value): bool
    {
        $words = preg_split('/[^a-z0-9]+/', str_replace('n/a', 'na', $this->ascii($value)), -1, PREG_SPLIT_NO_EMPTY);

        return collect($words)->reject(fn ($word) => in_array($word, self::EMPTY_WORDS, true))->isEmpty();
    }

    private function token(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', $this->ascii($value));
    }

    private function ascii(string $value): string
    {
        $value = mb_strtolower(str_replace(['I', 'İ'], ['ı', 'i'], $value), 'UTF-8');

        return strtr($value, ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u']);
    }
}
