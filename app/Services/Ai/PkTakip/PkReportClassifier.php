<?php

namespace App\Services\Ai\PkTakip;

use App\Services\PeriodicEquipmentSpecCatalog;

/**
 * Tür bulma (kısa talimatın 1. adımı): raporun ilk sayfalarından katalogdaki ekipman türünü ve raporun tek ekipman mı
 * yoksa çok ekipmanlı liste mi (toplu tüp formu gibi) olduğunu seçer. Çıktı küçük şemayla sınırlı; tür uydurulamaz.
 */
class PkReportClassifier
{
    private const UNKNOWN = 'bilinmiyor';
    // Asansör raporu ama cinsi (insan / yük / insan ve yük) okunamadı: rapor okunur, türü uzman seçer.
    public const ELEVATOR_UNREAD = 'asansor-cinsi-okunamadi';

    public function __construct(private PeriodicEquipmentSpecCatalog $catalog)
    {
    }

    /** @return array{slug: ?string, list: bool} */
    public function classify(array $pages, ?string $provider = null): array
    {
        $types = collect((array) ($this->catalog->promptCatalog()['types'] ?? []))->filter(fn ($type) => filled($type['slug'] ?? null));
        $slugs = $types->pluck('slug')->all();
        $list = $types->map(fn ($type) => '- ' . $type['slug'] . ' | ' . $type['name'] . (filled($type['category'] ?? null) ? ' (' . $type['category'] . ')' : ''))->implode("\n");
        // İlk üç sayfa: rapor başlığı, ekipman bilgileri; liste raporlarında ekipman tablosunun başı.
        $text = mb_substr(collect($pages)->take(3)->map(fn ($page) => trim((string) $page))->implode("\n\n"), 0, 12000);

        $system = <<<PROMPT
Bir periyodik kontrol raporunun ilk sayfaları verilecek. Raporun konusu olan ekipman türünü aşağıdaki listeden seç.
- slug: listedeki türlerden biri. Türü raporun başlığında, "periyodik kontrol metodu" satırında (örn. "TS EN ISO 8528-13 JENERATÖR PERİYODİK KONTROLÜ" → jeneratör; "YÜKSELTİLEBİLEN SEYYAR İŞ PLATFORMLARI" → makaslı / iş platformu), ekipman bilgilerinde ya da kontrol maddelerinde ara; en yakın türü seç.
  "bilinmiyor" yalnızca rapor bir tesisat raporuysa (yangın, elektrik, topraklama tesisatı gibi), birden çok farklı türde ekipman içeriyorsa ya da hiçbir türe benzemiyorsa.
  Tek tek taşınabilir cihazların (örn. yangın söndürücüler) kontrol formu tesisat raporu DEĞİLDİR; başlıktaki cihaz adına en yakın türü seç. "Taşınabilir söndürücü" / "taşınabilir yangın söndürme cihazı" (KKT, CO2, köpüklü söndürücüler) bir yangın söndürme cihazıdır; "taşınabilir gaz tüpü" türü LPG, oksijen, asetilen gibi gaz silindirleri içindir, söndürücü DEĞİLDİR.
  Asansör kontrol raporu tek bir asansör içindir, "bilinmiyor" değildir. Asansör türünü raporda işaretli "asansör cinsi"nden seç: İnsan → İnsan Asansörü, Yük → Yük / Servis Asansörü, İnsan ve Yük → İnsan Taşımalı Yük Asansörü.
  Cinsi yalnızca şu durumlarda seç: seçeneklerden yalnızca biri yazıyorsa ya da birinin yanında işaret (X, ☒, ✔, ■ gibi) varsa. Metinde seçeneklerin hepsi işaretsiz yan yana yazıyorsa (örn. "ASANSÖR CİNSİ: İNSAN YÜK İNSAN VE YÜK") hangisinin seçildiği okunamaz: tahmin etme, "asansor-cinsi-okunamadi" seç.
- list: rapor TEK bir ekipmanı mı konu alıyor (false), yoksa aynı türden ÇOK SAYIDA ekipmanı tablo hâlinde mi listeliyor (true; örn. onlarca yangın tüpünün tek formda kontrolü)?
  Liste raporunda türü, listelenen ekipmanların ortak adından ve tablonun sütunlarından (cihaz tipi, kapasite, dolum tarihi gibi) çıkar; adı benzeyen türleri (örn. farklı "tüp" türleri) bu ipuçlarıyla ayırt et.
- evidence: kararın dayanağı, raporda yazan kısa ifade.
TÜRLER (slug | ad (kategori)):
{$list}
PROMPT;
        $schema = [
            'type' => 'object',
            'properties' => [
                'slug' => ['type' => 'string', 'enum' => [...$slugs, self::ELEVATOR_UNREAD, self::UNKNOWN]],
                'list' => ['type' => 'boolean'],
                'evidence' => ['type' => 'string'],
            ],
            'required' => ['slug', 'list', 'evidence'],
        ];

        $result = PkAiProvider::client($provider)->extract($system, $text, 2000, schema: $schema);
        $slug = (string) ($result['slug'] ?? '');
        // Karışan çift: küçük model "TAŞINABİLİR SÖNDÜRÜCÜLER" başlığında gaz tüpünü seçiyordu (NuExtract3, toplu form #10). Tek
        // soruluk doğrulama ajanı; kural yukarıdaki talimattan.
        if ($slug === 'tasinabilir-gaz-tupu' && in_array('yangin-sondurme-cihazi', $slugs, true)) {
            $check = PkAiProvider::client($provider)->extract(
                'Sana bir periyodik kontrol raporunun ilk sayfaları verilecek. Rapor hangi cihazların kontrolü? "sondurucu": yangın söndürücüler / yangın söndürme cihazları (KKT, CO2, köpüklü, taşınabilir söndürücü); "gaz_silindiri": LPG, oksijen, asetilen gibi gaz silindirleri (söndürücü değil).',
                $text,
                500,
                schema: ['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'enum' => ['sondurucu', 'gaz_silindiri']]], 'required' => ['kind']]
            );
            $slug = ($check['kind'] ?? null) === 'sondurucu' ? 'yangin-sondurme-cihazi' : $slug;
        }

        return ['slug' => in_array($slug, [...$slugs, self::ELEVATOR_UNREAD], true) ? $slug : null, 'list' => (bool) ($result['list'] ?? false)];
    }

    /**
     * Tesisat türü (ana okuma yokken, Docling akışı): ilk sayfalardan katalogdaki tesisat türlerinden biri.
     *
     * @param array $catalog PkInstallationReportReader::promptCatalog()
     * @return array{slug: ?string, evidence: ?string}
     */
    public function installationType(array $catalog, array $pages, ?string $provider = null): array
    {
        $slugs = array_values(array_filter(array_column($catalog, 'slug')));
        $list = collect($catalog)->map(fn ($type) => '- ' . ($type['slug'] ?? '') . ' | ' . ($type['name'] ?? ''))->implode("
");
        $text = mb_substr(collect($pages)->take(3)->map(fn ($page) => trim((string) $page))->implode("

"), 0, 12000);
        $schema = ['type' => 'object', 'properties' => [
            'slug' => ['type' => ['string', 'null'], 'enum' => [...$slugs, null]],
            'evidence' => ['type' => 'string'],
        ], 'required' => ['slug', 'evidence']];

        $result = PkAiProvider::client($provider)->extract("Sana bir tesisat periyodik kontrol raporunun ilk sayfaları verilecek. Raporun konusu olan tesisat türünü aşağıdaki listeden seç (rapor başlığına ve kontrol edilen sistemlere bak); hiçbiri değilse null. evidence: kararın dayanağı, raporda yazan kısa ifade.
TÜRLER (slug | ad):
" . $list, $text, 2000, schema: $schema);
        $slug = $result['slug'] ?? null;

        return ['slug' => in_array($slug, $slugs, true) ? $slug : null, 'evidence' => $result['evidence'] ?? null];
    }
}
