<?php

namespace App\Services\Ai\PkTakip;

/**
 * Tablo haritası alt ajanı (yerel model, tesisat, Docling akışı): Docling metninde "### Tablo N" ile işaretli tabloların
 * hangi sistemin hangi ekipmanına ait olduğunu, kimlik ve özellik etiketlerini söyler; tek tek anlatılan ekipmanın (pompa,
 * depo) değerlerini verir. Tablonun yapısını (yön, blok) modele sormaz: tabloyu Docling'in gösterdiği alanda Camelot okur.
 */
class PkTableMap
{
    private const PROMPT = <<<'PROMPT'
Sana bir tesisat periyodik kontrol raporu verilecek; tablolar "### Tablo N" ile numaralı (uzun tabloların yalnız başı görünür). Raporda bulunan sistemler ve ekipman türleri aşağıda. HER sistem için (hiçbirini atlama) o sistemin ekipmanlarını yaz:
- lists: aynı türden çok sayıda ekipmanı listeleyen tablolar (örn. dolap, hidrant listesi). tables: listenin tablo numaraları; liste sonraki sayfalarda devam ediyorsa devam tablolarını da yaz. identity_label: ekipman numarasının / kodunun etiketi (örn. "Dolap No"). property_labels: ekipmanın HER özellik etiketi (marka, yer ve ölçülen değerler: basınç, uzunluk, mesafe gibi); değeri uygun / uygun değil gibi bir sonuç olan kriter satırlarını yazma.
- singles: kendi başlığı / bilgi tablosuyla tek tek anlatılan ekipmanlar (örn. 1, 2, 3 numaralı pompa, su deposu); HER biri ayrı kayıt, birini yazıp diğerlerini atlama. table: bilgilerinin olduğu tablo numarası (yoksa null). identity_value: ekipmanın numarası / adı (örn. "1 NUMARALI POMPA"). properties: etiket ve değerleri, raporda yazdığı gibi. Bütün değerleri boş ("-") olan yuva gerçek bir ekipman değildir; yazma.
- Kontrol listesi (soru / kriter maddeleri ve sonuçları), rapor bilgileri, proje / tesis bilgileri tabloları ekipman değildir; yazma. Sistemde ekipman yoksa lists ve singles boş.
- equipment_name: o sistemin ekipman türlerinden biri, AYNEN.
PROMPT;

    /**
     * @param array<string, list<string>> $systems sistem adı => ekipman türleri
     * @param list<int> $tableNos Docling tablo numaraları
     * @param list<string> $labels Camelot etiketleri (etiketler bu listeden seçilir)
     * @return array<string, array{lists: list<array>, singles: list<array>}>
     */
    // $rules: türün ek kuralları (örn. elektrik talimatının ölçüm listeleri bölümü: ekipman adları); katalogda ekipman türü
    // olmayan sistemde ekipman adı bu kurallara göre yazılır.
    public function map(string $documentText, array $systems, array $tableNos, array $labels, ?string $provider = null, string $rules = ''): array
    {
        $names = array_keys($systems);
        if (!$names) {
            return [];
        }
        $equipment = collect($systems)->flatten()->unique()->values()->all();
        $label = $labels ? ['type' => 'string', 'enum' => $labels] : ['type' => 'string'];
        $text = ['type' => ['string', 'null']];
        $table = ['type' => 'integer', 'enum' => $tableNos];
        $item = ['type' => 'object', 'properties' => [
            'singles' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'equipment_name' => $equipment ? ['type' => 'string', 'enum' => $equipment] : ['type' => 'string'],
                'table' => ['type' => ['integer', 'null'], 'enum' => [...$tableNos, null]],
                'identity_value' => $text,
                'properties' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string'], 'value' => $text], 'required' => ['label', 'value']]],
            ], 'required' => ['equipment_name', 'table', 'identity_value', 'properties']]],
            'lists' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'equipment_name' => $equipment ? ['type' => 'string', 'enum' => $equipment] : ['type' => 'string'],
                'tables' => ['type' => 'array', 'items' => $table],
                'identity_label' => $label,
                'property_labels' => ['type' => 'array', 'items' => $label],
            ], 'required' => ['equipment_name', 'tables', 'identity_label', 'property_labels']]],
        ], 'required' => ['singles', 'lists']];
        $list = collect($systems)->map(fn ($types, $name) => '- ' . $name . ': ' . ($types ? implode(', ', $types) : '(ekipman türü yok)'))->implode("\n");

        $found = PkLocalRetry::client($provider, fn (array $r) => !collect($r)->contains(fn ($s) => !empty($s['lists']) || !empty($s['singles'])))
            ->extract(self::PROMPT . "\nSİSTEMLER (sistem: ekipman türleri):\n" . $list . ($rules !== '' ? "\n\nEK KURALLAR (ekipman türü yoksa equipment_name buna göre):\n" . $rules : ''), $documentText, 8000, schema: ['type' => 'object', 'properties' => array_fill_keys($names, $item), 'required' => $names]);

        return collect($names)->mapWithKeys(fn ($name) => [$name => [
            'lists' => array_values(array_filter((array) ($found[$name]['lists'] ?? []), 'is_array')),
            'singles' => array_values(array_filter((array) ($found[$name]['singles'] ?? []), 'is_array')),
        ]])->all();
    }
}
