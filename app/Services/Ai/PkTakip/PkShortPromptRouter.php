<?php

namespace App\Services\Ai\PkTakip;

/**
 * Kısa talimat yönlendirmesi (config pktakip.short_prompts açıkken PkReportAnalyzer::analyze başında çağrılır):
 * - Türü belli tek ekipman raporu → kısa tek ekipman talimatı.
 * - Tür bilinmiyor → önce tür bulma (PkReportClassifier). Tek ekipmansa kısa talimat; çok ekipmanlı listeyse (toplu tüp
 *   formu) mevcut talimat, katalogdan yalnızca o tür verilerek.
 * - Tesisat raporu, görüntü PDF ya da tür bulunamazsa null: mevcut akış aynen çalışır.
 * Dönüş: ['semantic' => ...] (okundu) | ['equipment' => ...] (mevcut talimat, daraltılmış katalogla) | null.
 */
class PkShortPromptRouter
{
    public function __construct(
        private PkReportClassifier $classifier,
        private PkSingleEquipmentReader $single,
        private PkBulkListPrompt $bulkList
    ) {
    }

    public function route(array $pages, array $equipment, ?string $pdfPath = null): ?array
    {
        if (!empty($equipment['installation_catalog'])) {
            return null;
        }

        if (empty($equipment['catalog'])) {
            $slug = $equipment['slug'] ?? null;

            return $slug && $slug !== PkBulkReportReader::TYPE_SLUG ? ['semantic' => $this->readSingle($pages, $slug, $pdfPath)] : null;
        }

        $found = $this->classifier->classify($pages);
        if (!$found['slug']) {
            return null;
        }
        // Toplu tüp formu: kısa toplu form talimatı (tür ve liste kararı tür bulmadan, yani yapay zekadan gelir).
        if ($found['list'] && $found['slug'] === PkBulkReportReader::TYPE_SLUG) {
            return ['semantic' => $this->bulkList->read($pages, $found['slug'])];
        }
        // Yerel model "liste" kararında tekli raporu da liste sanabiliyor (NuExtract3, transpalet 23 Eki); tüp dışı toplu form
        // veride yok. shortcut: tüp dışı liste raporu yerelde tekli ajanlarla okunur; böyle bir form gelirse bu kol açılır.
        if ($found['list'] && PkAiProvider::name() === 'local') {
            return ['semantic' => $this->readSingle($pages, $found['slug'], $pdfPath)];
        }
        if ($found['list']) {
            $catalog = $equipment['catalog'];
            $catalog['types'] = array_values(array_filter((array) ($catalog['types'] ?? []), fn ($type) => ($type['slug'] ?? null) === $found['slug']));

            $name = $catalog['types'][0]['name'] ?? $found['slug'];

            return ['equipment' => ['catalog' => $catalog, 'note' => "ÖN İNCELEME: Bu rapor tek ekipman raporu değildir; \"{$name}\" türünden çok sayıda ekipmanı tablo hâlinde listeleyen toplu kontrol formudur. report_category ve equipment_axis'i buna göre seç.\n"
                . "Tablo okuyucu sütunları başlık adıyla bulur: identity_field, header_patterns ve attributes[].source_pattern tablonun sütun başlıklarıyla HARFİ HARFİNE aynı olmalı; kısaltma ya da uydurma ad yazma, hiçbir sütunu atlama (yer / konum sütunu dahil). "
                . "PDF metninde başlık satırı tablonun satırlarından sonra gelebilir ve başlıklar satırlara bölünmüş olabilir (örn. \"Tüp\" ve \"No\" alt alta → \"Tüp No\"); başlığı birleştirerek yaz. "
                . "Ekipmanın yerini gösteren sütun (örn. \"Cihazın Bulunduğu yer\", \"Bulunduğu Yer\", \"Konum\") tabloda varsa header_patterns'a MUTLAKA yaz; tüp no ve değerlendirme / sonuç sütunu da header_patterns'ta olmalı."]];
        }

        if ($found['slug'] === PkReportClassifier::ELEVATOR_UNREAD) {
            // Asansör talimatı türden bağımsız; katalog alanları için bir asansör türüyle okunur, tür boş bırakılır.
            $semantic = $this->single->readPages($pages, 'insan-asansoru');
            $semantic['extracted_data']['equipment_type'] = ['slug' => null, 'evidence' => 'Asansör cinsi (insan / yük / insan ve yük) rapordan okunamadı; türü seçin.'];

            return ['semantic' => $semantic];
        }

        return ['semantic' => $this->readSingle($pages, $found['slug'], $pdfPath)];
    }

    // Tek ekipman: küçük ajanlarla (rapor bilgisi, ekipman, bulgular; asansörde sayfa sayfa bulgu + müşteri).
    private function readSingle(array $pages, string $slug, ?string $pdfPath): array
    {
        return $this->single->readPagesAgents($pages, $slug, pdfPath: $pdfPath);
    }
}
