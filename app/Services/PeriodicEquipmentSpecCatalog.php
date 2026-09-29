<?php

namespace App\Services;

use App\Models\PeriodicEquipmentSpec;
use App\Models\PeriodicEquipmentType;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ekipman teknik özellik kataloğu: türün özellikleri (ortak + türe özel), rapordaki başlığın katalog özelliğiyle eşlenmesi,
 * birim sadeleştirme ve kataloğa ekleme (yalnızca yetkili: super-admin ya da PKTAKIP_CATALOG_ADMINS).
 * Böylece farklı muayene firmalarının farklı adlandırmaları ("Kapasite", "Kaldırma Kapasitesi (kg)") tek özellikte toplanır.
 */
class PeriodicEquipmentSpecCatalog
{
    // Karşılaştırmada eşdeğer sayılan kısaltmalar.
    private const SYNONYMS = ['maksimum' => 'maks', 'minimum' => 'min', 'numarasi' => 'no', 'numara' => 'no', 'nosu' => 'no'];

    /** Türün özellikleri: önce ortak (marka, model...), sonra türe özel; sıralı. */
    public function specsFor(?PeriodicEquipmentType $type): Collection
    {
        return PeriodicEquipmentSpec::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('equipment_type_id')->when($type, fn ($q) => $q->orWhere('equipment_type_id', $type->id)))
            ->orderByRaw('equipment_type_id is not null')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /** Rapordaki başlık ("Kaldırma Kapasitesi (kg)", "Markası") katalogdaki hangi özellik: ad ya da diğer adlarla. */
    public function match(string $label, Collection $specs): ?PeriodicEquipmentSpec
    {
        $needle = $this->normalize($label);
        if ($needle === '') {
            return null;
        }

        return $specs->first(fn (PeriodicEquipmentSpec $spec) => collect([$spec->name, ...($spec->aliases ?? [])])
            ->contains(fn ($name) => $this->normalize((string) $name) === $needle));
    }

    /** Değeri katalog birimine göre sadeleştirir: "2000 kg" (birim kg) → "2000". Başka birimdeyse olduğu gibi bırakır. */
    public function plainValue(string $value, ?string $unit): string
    {
        $value = trim($value);
        if ($unit && preg_match('/^([\d.,]+)\s*' . preg_quote($unit, '/') . '\.?$/iu', $value, $m)) {
            return $m[1];
        }

        return $value;
    }

    public function canEdit(?User $user): bool
    {
        if (!$user) {
            return false;
        }
        $admins = array_filter(array_map(fn ($email) => mb_strtolower(trim($email)), explode(',', (string) config('pktakip.catalog_admins', ''))));
        if (in_array(mb_strtolower((string) $user->email), $admins, true)) {
            return true;
        }

        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $user->getKey())
            ->where('model_has_roles.model_type', $user::class)
            ->where('roles.guard_name', 'web')
            ->where('roles.name', 'super-admin')
            ->exists();
    }

    /** Yetkili: türün kataloğuna yeni özellik; rapordaki başlık diğer ad olarak eklenir. */
    public function addSpec(PeriodicEquipmentType $type, string $name, ?string $unit, ?string $rawLabel, User $user): PeriodicEquipmentSpec
    {
        $this->authorize($user);
        $name = trim($name);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Özellik adı boş olamaz.']);
        }
        $specs = $this->specsFor($type);
        if ($existing = $this->match($name, $specs)) {
            throw ValidationException::withMessages(['name' => "Katalogda zaten var: {$existing->name}."]);
        }

        return PeriodicEquipmentSpec::create([
            'equipment_type_id' => $type->id,
            'key' => $this->uniqueKey($type, $this->keyFor($name)),
            'name' => $name,
            'unit' => filled($unit) ? trim($unit) : null,
            'aliases' => filled($rawLabel) && $this->normalize($rawLabel) !== $this->normalize($name) ? [trim($rawLabel)] : [],
            'sort_order' => (int) $specs->where('equipment_type_id', $type->id)->max('sort_order') + 1,
            'created_by' => $user->id,
        ]);
    }

    /** Yetkili: rapordaki başlığı mevcut özelliğin diğer adı olarak kaydeder (sonraki raporlarda doğrudan eşlenir). */
    public function addAlias(PeriodicEquipmentSpec $spec, string $label, User $user): void
    {
        $this->authorize($user);
        $label = trim($label);
        if ($label === '' || $this->match($label, collect([$spec]))) {
            return;
        }
        $spec->update(['aliases' => array_values(array_unique([...($spec->aliases ?? []), $label]))]);
    }

    /**
     * Rapordan ekipman tanımlarken Gemini'ye verilen katalog: ekipman türleri (tesisatlar hariç), etiketleri ve
     * türe özel özellikleri; ortak özellikler ayrıca.
     */
    public function promptCatalog(): array
    {
        $types = PeriodicEquipmentType::query()
            ->where('is_active', true)
            ->whereHas('category', fn ($q) => $q->where('kind', 'equipment')->where('is_active', true))
            ->with('category:id,name')
            ->orderBy('category_id')->orderBy('sort_order')
            ->get();
        $specs = PeriodicEquipmentSpec::query()->where('is_active', true)->orderBy('sort_order')->get()->groupBy(fn ($spec) => (int) $spec->equipment_type_id);
        $shape = fn ($spec) => ['key' => $spec->key, 'name' => $spec->name, 'unit' => $spec->unit];

        return [
            'common_specs' => ($specs[0] ?? collect())->map($shape)->values()->all(),
            'types' => $types->map(fn (PeriodicEquipmentType $type) => [
                'slug' => $type->slug,
                'name' => $type->name,
                'category' => $type->category?->name,
                'tag' => $type->variant_label && $type->variants ? ['label' => $type->variant_label, 'options' => $type->variants] : null,
                'specs' => ($specs[$type->id] ?? collect())->map($shape)->values()->all(),
            ])->values()->all(),
        ];
    }

    /** Gemini'nin tür seçimi yoksa (eski analiz / fikstür): rapordaki ekipman / bölüm adından ekipman türü tahmini. */
    public function guessType(array $semantic): ?PeriodicEquipmentType
    {
        $types = PeriodicEquipmentType::query()->where('is_active', true)->whereHas('category', fn ($q) => $q->where('kind', 'equipment'))->get();
        $names = [];
        foreach ((array) ($semantic['template']['fire_systems']['systems'] ?? []) as $system) {
            foreach ((array) ($system['equipment_definitions'] ?? []) as $definition) {
                $names[] = (string) ($definition['equipment_name'] ?? '');
            }
            $names[] = (string) ($system['system_name'] ?? '');
        }
        foreach (array_filter($names) as $name) {
            $normalized = $this->normalize($name);
            if ($type = $types->first(fn ($type) => $this->normalize($type->name) === $normalized)) {
                return $type;
            }
        }

        return null;
    }

    /** "Kaldırma kapasitesi" → "kaldirma_kapasitesi" */
    public function keyFor(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', $this->ascii($name)), '_') ?: 'ozellik';
    }

    // Karşılaştırma: Türkçe karakter, büyük/küçük harf, parantez içi birim ve noktalama farkı yok sayılır.
    public function normalize(string $label): string
    {
        $text = preg_replace('/\([^)]*\)/', ' ', $this->ascii($label));
        $words = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        return implode('', array_map(fn ($word) => self::SYNONYMS[$word] ?? $word, $words));
    }

    private function uniqueKey(PeriodicEquipmentType $type, string $key): string
    {
        $taken = PeriodicEquipmentSpec::query()->where(fn ($q) => $q->whereNull('equipment_type_id')->orWhere('equipment_type_id', $type->id))->pluck('key')->all();
        $candidate = $key;
        for ($i = 2; in_array($candidate, $taken, true); $i++) {
            $candidate = "{$key}_{$i}";
        }

        return $candidate;
    }

    private function authorize(User $user): void
    {
        if (!$this->canEdit($user)) {
            abort(403, 'Kataloğu yalnızca yetkili kullanıcı değiştirebilir.');
        }
    }

    private function ascii(string $value): string
    {
        $value = mb_strtolower(str_replace(['I', 'İ'], ['ı', 'i'], $value), 'UTF-8');

        return strtr($value, ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u']);
    }
}
