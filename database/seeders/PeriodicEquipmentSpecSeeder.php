<?php

namespace Database\Seeders;

use App\Models\PeriodicEquipmentSpec;
use App\Models\PeriodicEquipmentType;
use App\Services\PeriodicEquipmentSpecCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * pktakip ekipman teknik özellik kataloğu (onaylanan taslak). Her özellik: [ad, birim, diğer adlar].
 * İdempotent: (tür, anahtar) üzerinden günceller; yetkilinin sonradan eklediği özelliklere ve diğer adlara dokunmaz
 * (diğer adlar birleştirilir). PeriodicEquipmentCatalogSeeder'dan sonra çalıştırılır.
 */
class PeriodicEquipmentSpecSeeder extends Seeder
{
    // Her türde ortak.
    private const COMMON = [
        ['Marka', null, ['Markası', 'Marka Adı', 'İmalatçı']],
        ['Model', null, ['Model/Tipi', 'Modeli', 'Tipi / Modeli']],
        ['Seri no', null, ['Seri Numarası', 'Seri Nosu', 'İmalat No', 'Fabrika No']],
        ['Ekipman no', null, ['Makine No', 'Envanter No', 'Sicil No', 'Demirbaş No', 'Ekipman Kodu']],
        ['Bulunduğu yer', null, ['Çalışma Alanı veya Bölümü', 'Çalışma Alanı', 'Bulunduğu Bölüm', 'Konum', 'Kullanım Yeri']],
    ];

    private const Y = ['Üretim yılı', null, ['İmal Yılı', 'İmalat Yılı', 'Yapım Yılı']];

    private const TYPES = [
        // Basınçlı Kaplar
        'buhar-kazani' => [self::Y, ['Hacim', 'L'], ['Buhar kapasitesi', 'kg/h'], ['Çalışma basıncı', 'bar'], ['Test basıncı', 'bar'], ['Emniyet ventili açma basıncı', 'bar'], ['Isıl güç', 'kW']],
        'kalorifer-kazani' => [self::Y, ['Isıl güç', 'kW'], ['Hacim', 'L'], ['Çalışma basıncı', 'bar'], ['Test basıncı', 'bar'], ['Çalışma sıcaklığı', '°C'], ['Emniyet ventili açma basıncı', 'bar']],
        'hidrofor' => [self::Y, ['Tank hacmi', 'L'], ['Çalışma basıncı', 'bar'], ['Test basıncı', 'bar'], ['Pompa gücü', 'kW'], ['Pompa sayısı', 'adet']],
        'genlesme-tanki' => [self::Y, ['Hacim', 'L'], ['Çalışma basıncı', 'bar'], ['Test basıncı', 'bar'], ['Ön basınç', 'bar']],
        'boyler' => [self::Y, ['Hacim', 'L'], ['Çalışma basıncı', 'bar'], ['Test basıncı', 'bar'], ['Çalışma sıcaklığı', '°C']],
        'basincli-hava-tanki' => [self::Y, ['Tank hacmi', 'L'], ['Çalışma basıncı', 'bar'], ['Test basıncı', 'bar'], ['Emniyet ventili açma basıncı', 'bar'], ['Motor gücü', 'kW']],
        'otoklav' => [self::Y, ['Hacim', 'L'], ['Çalışma basıncı', 'bar'], ['Test basıncı', 'bar'], ['Çalışma sıcaklığı', '°C']],
        'lpg-tanki' => [self::Y, ['Hacim', 'm³'], ['Tasarım basıncı', 'bar'], ['Test basıncı', 'bar']],
        'lpg-tupu' => [self::Y, ['Dara', 'kg'], ['Test basıncı', 'bar']],
        'tasinabilir-gaz-tupu' => [self::Y, ['Hacim', 'L'], ['Dolum basıncı', 'bar'], ['Test basıncı', 'bar'], ['Son hidrostatik test tarihi', null]],
        'asetilen-tupu' => [self::Y, ['Hacim', 'L'], ['Dolum basıncı', 'bar'], ['Test basıncı', 'bar'], ['Son hidrostatik test tarihi', null]],
        'manifoldlu-tup-demeti' => [self::Y, ['Tüp sayısı', 'adet'], ['Toplam hacim', 'L'], ['Dolum basıncı', 'bar'], ['Test basıncı', 'bar']],
        'kriyojenik-tank' => [self::Y, ['Hacim', 'L'], ['Çalışma basıncı', 'bar'], ['Test basıncı', 'bar'], ['Emniyet ventili açma basıncı', 'bar']],
        'tehlikeli-sivi-tanki' => [self::Y, ['Hacim', 'm³'], ['Depolanan madde', null], ['Tank malzemesi', null], ['Test basıncı', 'bar']],
        // Kaldırma ve İletme
        'forklift' => [self::Y, ['Kaldırma kapasitesi', 'kg', ['Kapasite', 'Taşıma Kapasitesi']], ['Maks. kaldırma yüksekliği', 'mm', ['Kaldırma Yüksekliği']], ['Yük merkezi mesafesi', 'mm'], ['Direk tipi', null], ['Çatal boyu', 'mm', ['Çatal Kol Bıçak Boyu', 'Çatal Uzunluğu']]],
        'istif-makinesi' => [self::Y, ['Kaldırma kapasitesi', 'kg', ['Kapasite']], ['Maks. kaldırma yüksekliği', 'mm', ['Kaldırma Yüksekliği']], ['Yük merkezi mesafesi', 'mm'], ['Çatal boyu', 'mm', ['Çatal Kol Bıçak Boyu', 'Çatal Uzunluğu']]],
        'transpalet' => [self::Y, ['Kaldırma kapasitesi', 'kg', ['Kapasite']], ['Maks. kaldırma yüksekliği', 'mm', ['Kaldırma Yüksekliği']], ['Yük merkezi mesafesi', 'mm'], ['Çatal boyu', 'mm', ['Çatal Kol Bıçak Boyu', 'Çatal Uzunluğu']]],
        'kopru-vinc' => [self::Y, ['Kaldırma kapasitesi', 'kg', ['Kapasite', 'Taşıma Kapasitesi']], ['Açıklık', 'm'], ['Kaldırma yüksekliği', 'm'], ['Kumanda şekli', null]],
        'portal-vinc' => [self::Y, ['Kaldırma kapasitesi', 'kg', ['Kapasite', 'Taşıma Kapasitesi']], ['Açıklık', 'm'], ['Kaldırma yüksekliği', 'm'], ['Kumanda şekli', null]],
        'kule-vinc' => [self::Y, ['Maks. kaldırma kapasitesi', 'kg', ['Kapasite']], ['Bom uzunluğu', 'm'], ['Uç kapasitesi', 'kg'], ['Kanca yüksekliği', 'm']],
        'mobil-vinc' => [self::Y, ['Maks. kaldırma kapasitesi', 'kg', ['Kapasite']], ['Bom uzunluğu', 'm'], ['Plaka', null], ['Şasi no', null]],
        'caraskal' => [self::Y, ['Kaldırma kapasitesi', 'kg', ['Kapasite']], ['Kaldırma yüksekliği', 'm'], ['Halat / zincir tipi', null]],
        'arac-kaldirma-lifti' => [self::Y, ['Kaldırma kapasitesi', 'kg', ['Kapasite']], ['Kaldırma yüksekliği', 'mm']],
        'makasli-platform' => [self::Y, ['Platform kapasitesi', 'kg', ['Kapasite']], ['Maks. çalışma yüksekliği', 'm', ['Çalışma Yüksekliği']], ['Kişi kapasitesi', 'kişi']],
        'sepetli-platform' => [self::Y, ['Sepet kapasitesi', 'kg', ['Kapasite']], ['Maks. çalışma yüksekliği', 'm', ['Çalışma Yüksekliği']], ['Yatay erişim', 'm'], ['Kişi kapasitesi', 'kişi'], ['Plaka', null]],
        'yuk-asansoru' => [self::Y, ['Beyan yükü', 'kg', ['Kapasite', 'Taşıma Kapasitesi']], ['Hız', 'm/s'], ['Durak sayısı', 'adet'], ['Tescil no', null]],
        'yuruyen-merdiven' => [self::Y, ['Hız', 'm/s'], ['Kot farkı', 'm'], ['Basamak genişliği', 'mm'], ['Eğim', '°']],
        'konveyor' => [self::Y, ['Uzunluk', 'm'], ['Bant genişliği', 'mm'], ['Hız', 'm/s'], ['Taşıma kapasitesi', 'kg', ['Kapasite']]],
        'kaldirma-aksesuari' => [['Çalışma yük limiti', 'kg', ['ÇYL', 'WLL', 'Kapasite']], ['Uzunluk', 'm'], ['Çap / kalınlık', 'mm'], ['Sınıf (grade)', null], ['Emniyet katsayısı', null]],
        'yapi-iskelesi' => [['Kurulum tarihi', null], ['Yükseklik', 'm'], ['Uzunluk', 'm'], ['Yük sınıfı', null]],
        'cephe-iskelesi' => [self::Y, ['Kapasite', 'kg'], ['Platform uzunluğu', 'm'], ['Çalışma yüksekliği', 'm'], ['Halat çapı', 'mm']],
        // Asansörler
        'insan-asansoru' => [self::Y, ['Beyan yükü', 'kg', ['Kapasite']], ['Kişi kapasitesi', 'kişi'], ['Hız', 'm/s'], ['Durak sayısı', 'adet'], ['Tescil no', null]],
        'yuk-asansoru-insan-tasimali' => [self::Y, ['Beyan yükü', 'kg', ['Kapasite']], ['Kişi kapasitesi', 'kişi'], ['Hız', 'm/s'], ['Durak sayısı', 'adet'], ['Tescil no', null]],
        'engelli-platformu' => [self::Y, ['Kapasite', 'kg'], ['Kaldırma yüksekliği', 'm'], ['Hız', 'm/s']],
        // Yangın Ekipmanları
        'yangin-sondurme-cihazi' => [self::Y, ['Dolum ağırlığı', 'kg', ['Kapasite']], ['Yangın sınıfı', null], ['Son dolum tarihi', null], ['Hidrostatik test tarihi', null]],
        'yangin-dolabi' => [['Hortum uzunluğu', 'm'], ['Hortum çapı', null], ['Lans tipi', null], ['Dolap tipi', null]],
        'yangin-pompasi' => [self::Y, ['Debi', 'm³/h'], ['Basma yüksekliği', 'mSS', ['Basınç']], ['Motor gücü', 'kW', ['Güç']], ['Devir', 'd/dk']],
        'hidrant' => [['Çıkış sayısı', 'adet'], ['Çıkış çapı', null], ['Statik basınç', 'bar'], ['Debi', 'L/dk']],
        // Tezgahlar
        'mekanik-pres' => [self::Y, ['Kapasite', 'ton'], ['Kurs boyu', 'mm'], ['Vuruş sayısı', 'vuruş/dk'], ['Emniyet tertibatı', null]],
        'hidrolik-pres' => [self::Y, ['Kapasite', 'ton'], ['Kurs boyu', 'mm'], ['Çalışma basıncı', 'bar'], ['Tabla ölçüsü', null], ['Emniyet tertibatı', null]],
        'abkant-pres' => [self::Y, ['Kapasite', 'ton'], ['Büküm boyu', 'mm'], ['Emniyet tertibatı', null]],
        'giyotin-makas' => [self::Y, ['Kesme boyu', 'mm'], ['Kesme kalınlığı', 'mm'], ['Emniyet tertibatı', null]],
        'torna' => [self::Y, ['Punta arası', 'mm'], ['Tornalama çapı', 'mm'], ['Motor gücü', 'kW']],
        'freze' => [self::Y, ['Tabla ölçüsü', null], ['Motor gücü', 'kW']],
        'cnc' => [self::Y, ['Kontrol ünitesi', null], ['Tabla ölçüsü', null], ['Motor gücü', 'kW']],
        'taslama' => [self::Y, ['Taş çapı', 'mm'], ['Taş devri', 'd/dk'], ['Motor gücü', 'kW']],
        'matkap' => [self::Y, ['Delme kapasitesi', 'mm'], ['Motor gücü', 'kW']],
        'agac-isleme' => [self::Y, ['Bıçak / testere çapı', 'mm'], ['Motor gücü', 'kW'], ['Emniyet tertibatı', null]],
        // Endüstriyel Raf ve Kapılar
        'depolama-rafi' => [['Kurulum yılı', null], ['Göz yük kapasitesi', 'kg', ['Kapasite']], ['Kat sayısı', 'adet'], ['Raf yüksekliği', 'm'], ['Raf uzunluğu', 'm']],
        'endustriyel-kapi' => [self::Y, ['Genişlik', 'mm'], ['Yükseklik', 'mm'], ['Tahrik', null]],
        'yukleme-rampasi' => [self::Y, ['Kapasite', 'kg'], ['Genişlik', 'mm'], ['Uzunluk', 'mm']],
        // İş Makineleri
        'ekskavator' => [self::Y, ['Çalışma ağırlığı', 'ton'], ['Kova kapasitesi', 'm³'], ['Motor gücü', 'kW'], ['Şasi no', null], ['Plaka', null]],
        'loder' => [self::Y, ['Çalışma ağırlığı', 'ton'], ['Kova kapasitesi', 'm³'], ['Motor gücü', 'kW'], ['Şasi no', null], ['Plaka', null]],
        'beko-loder' => [self::Y, ['Çalışma ağırlığı', 'ton'], ['Ön kova kapasitesi', 'm³'], ['Arka kova kapasitesi', 'm³'], ['Motor gücü', 'kW'], ['Şasi no', null], ['Plaka', null]],
        'dozer' => [self::Y, ['Çalışma ağırlığı', 'ton'], ['Bıçak genişliği', 'mm'], ['Motor gücü', 'kW'], ['Şasi no', null]],
        'greyder' => [self::Y, ['Çalışma ağırlığı', 'ton'], ['Bıçak genişliği', 'mm'], ['Motor gücü', 'kW'], ['Şasi no', null], ['Plaka', null]],
        'silindir' => [self::Y, ['Çalışma ağırlığı', 'ton'], ['Bilya genişliği', 'mm'], ['Motor gücü', 'kW'], ['Şasi no', null]],
        'damperli-kamyon' => [self::Y, ['Taşıma kapasitesi', 'ton'], ['Motor gücü', 'kW'], ['Şasi no', null], ['Plaka', null]],
    ];

    public function run(): void
    {
        $catalog = app(PeriodicEquipmentSpecCatalog::class);
        $types = PeriodicEquipmentType::query()->pluck('id', 'slug');

        DB::transaction(function () use ($catalog, $types) {
            foreach (self::COMMON as $order => $spec) {
                $this->upsert($catalog, null, $spec, $order + 1);
            }
            foreach (self::TYPES as $slug => $specs) {
                if (!isset($types[$slug])) {
                    continue;
                }
                foreach ($specs as $order => $spec) {
                    $this->upsert($catalog, $types[$slug], $spec, $order + 1);
                }
            }
        });
    }

    private function upsert(PeriodicEquipmentSpecCatalog $catalog, ?int $typeId, array $spec, int $order): void
    {
        [$name, $unit] = $spec;
        $key = $catalog->keyFor($name);
        $existing = PeriodicEquipmentSpec::query()->where('equipment_type_id', $typeId)->where('key', $key)->first();

        PeriodicEquipmentSpec::query()->updateOrCreate(
            ['equipment_type_id' => $typeId, 'key' => $key],
            [
                'name' => $name,
                'unit' => $unit,
                // Yetkilinin rapordan eklediği diğer adlar korunur.
                'aliases' => array_values(array_unique([...($existing?->aliases ?? []), ...($spec[2] ?? [])])),
                'sort_order' => $order,
                'is_active' => true,
            ]
        );
    }
}
