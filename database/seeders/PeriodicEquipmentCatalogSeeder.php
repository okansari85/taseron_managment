<?php

namespace Database\Seeders;

use App\Models\PeriodicEquipmentCategory;
use App\Models\PeriodicEquipmentType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * pktakip periyodik kontrol ekipman kataloğu (tüm kullanıcılarda ortak, ön tanımlı).
 * Periyotlar İş Ekipmanlarının Kullanımında Sağlık ve Güvenlik Şartları Yönetmeliği Ek-III'teki
 * azami sürelerdir (ay). null = ilgili standarda / üretici talimatına göre.
 * Tür (kind): equipment = ekipman, installation = tesisat; yazılımda ayrı değerlendirilir.
 * Kapsam: location = lokasyon geneli (bina/kampüs), workplace = işyerine özel (varsayılan öneri).
 * İdempotent: slug üzerinden updateOrCreate; tekrar çalıştırmak güvenlidir.
 */
class PeriodicEquipmentCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            ['basincli-kaplar', 'Basınçlı Kaplar', 'equipment', 'pi-gauge', [
                ['buhar-kazani', 'Buhar Kazanı', 12, 'location', 'Ek-III Tablo-1'],
                ['kalorifer-kazani', 'Kalorifer Kazanı', 12, 'location', 'Ek-III Tablo-1'],
                ['hidrofor', 'Hidrofor', 12, 'location', 'Ek-III Tablo-1'],
                ['genlesme-tanki', 'Genleşme Tankı', 12, 'location', 'Ek-III Tablo-1'],
                ['boyler', 'Boyler', 12, 'location', 'Ek-III Tablo-1'],
                ['basincli-hava-tanki', 'Basınçlı Hava Tankı (Kompresör)', 12, 'workplace', 'Ek-III Tablo-1'],
                ['otoklav', 'Otoklav', 12, 'workplace', 'Ek-III Tablo-1'],
                ['lpg-tanki', 'LPG Tankı (Yerüstü / Yeraltı)', 120, 'location', 'Ek-III Tablo-1 (10 yıl)'],
                ['lpg-tupu', 'LPG Tüpü (Kullanımdaki)', 12, 'workplace', 'Ek-III Tablo-1'],
                ['tasinabilir-gaz-tupu', 'Taşınabilir Gaz Tüpü', 36, 'workplace', 'Ek-III Tablo-1 (3 yıl)'],
                ['asetilen-tupu', 'Asetilen Tüpü', null, 'workplace', 'TS EN ISO 10462'],
                ['manifoldlu-tup-demeti', 'Manifoldlu Tüp Demeti', 12, 'workplace', 'Ek-III Tablo-1'],
                ['kriyojenik-tank', 'Kriyojenik Tank', null, 'location', 'TS EN ISO 21009-2'],
                ['tehlikeli-sivi-tanki', 'Tehlikeli Sıvı Tankı / Deposu', 120, 'location', 'Ek-III Tablo-1 (10 yıl)'],
            ]],
            ['kaldirma-iletme', 'Kaldırma ve İletme Ekipmanları', 'equipment', 'pi-arrow-up', [
                ['forklift', 'Forklift', 12, 'workplace', 'Ek-III Tablo-2'],
                ['istif-makinesi', 'İstif Makinesi', 12, 'workplace', 'Ek-III Tablo-2'],
                ['akulu-transpalet', 'Akülü Transpalet', 12, 'workplace', 'Ek-III Tablo-2'],
                ['kopru-vinc', 'Köprülü Vinç', 12, 'workplace', 'Ek-III Tablo-2'],
                ['portal-vinc', 'Portal Vinç', 12, 'workplace', 'Ek-III Tablo-2'],
                ['kule-vinc', 'Kule Vinç', 12, 'workplace', 'Ek-III Tablo-2'],
                ['mobil-vinc', 'Mobil Vinç', 12, 'workplace', 'Ek-III Tablo-2'],
                ['caraskal', 'Caraskal / Vinç Arabası', 12, 'workplace', 'Ek-III Tablo-2'],
                ['arac-kaldirma-lifti', 'Araç Kaldırma Lifti', 12, 'workplace', 'Ek-III Tablo-2'],
                ['makasli-platform', 'Makaslı Platform', 12, 'workplace', 'Ek-III Tablo-2'],
                ['sepetli-platform', 'Sepetli Platform', 12, 'workplace', 'Ek-III Tablo-2'],
                ['yuk-asansoru', 'Yük / Servis Asansörü', 12, 'location', 'Ek-III Tablo-2'],
                ['yuruyen-merdiven', 'Yürüyen Merdiven / Bant', 12, 'location', 'Ek-III Tablo-2'],
                ['konveyor', 'Konveyör', 12, 'workplace', 'Ek-III Tablo-2'],
                ['kaldirma-aksesuari', 'Kaldırma Aksesuarları (Zincir, Sapan, Kanca)', 12, 'workplace', 'Ek-III Tablo-2'],
                ['yapi-iskelesi', 'Yapı İskelesi', 6, 'workplace', 'Ek-III Tablo-2 (6 ay)'],
                ['cephe-iskelesi', 'Cephe İskelesi (Asılı)', 6, 'workplace', 'Ek-III Tablo-2 (6 ay)'],
            ]],
            ['asansorler', 'Asansörler', 'equipment', 'pi-sort-alt', [
                ['insan-asansoru', 'İnsan Asansörü', 12, 'location', 'Asansör Periyodik Kontrol Yönetmeliği'],
                ['yuk-asansoru-insan-tasimali', 'İnsan Taşımalı Yük Asansörü', 12, 'location', 'Asansör Periyodik Kontrol Yönetmeliği'],
                ['engelli-platformu', 'Engelli Kaldırma Platformu', 12, 'location', 'Asansör Periyodik Kontrol Yönetmeliği'],
            ]],
            ['tesisatlar', 'Tesisatlar', 'installation', 'pi-bolt', [
                ['elektrik-ic-tesisati', 'Elektrik İç Tesisatı', 12, 'location', 'Ek-III Tablo-3'],
                ['topraklama', 'Topraklama Tesisatı', 12, 'location', 'Ek-III Tablo-3'],
                ['paratoner', 'Paratoner (Yıldırımdan Korunma)', 12, 'location', 'Ek-III Tablo-3'],
                ['trafo', 'Trafo / Transformatör', 12, 'location', 'Ek-III Tablo-3'],
                ['akumulator', 'Akümülatör / UPS', 12, 'location', 'Ek-III Tablo-3'],
                ['jenerator', 'Jeneratör', 12, 'location', 'Ek-III Tablo-3'],
                ['havalandirma-klima', 'Havalandırma ve Klima Tesisatı', 12, 'location', 'Ek-III Tablo-3'],
                ['dogalgaz-tesisati', 'Doğalgaz / Boru Tesisatı', 12, 'location', 'Ek-III Tablo-3'],
                ['katodik-koruma', 'Katodik Koruma Tesisatı', 12, 'location', 'Ek-III Tablo-3'],
                ['yuksek-gerilim', 'Yüksek Gerilim Tesisatı', 12, 'location', 'Ek-III Tablo-3'],
            ]],
            ['yangin-sistemleri', 'Yangın Sistemleri', 'installation', 'pi-shield', [
                ['yangin-dolabi', 'Yangın Dolabı / Hortum Makarası', 12, 'location', 'TS EN 671-3'],
                ['sprinkler', 'Sprinkler Sistemi', 12, 'location', 'TS EN 12845'],
                ['hidrant', 'Hidrant', 12, 'location', 'TS 9811'],
                ['yangin-pompasi', 'Yangın Pompası', 12, 'location', 'TS EN 12845'],
                ['yangin-su-deposu', 'Yangın Su Deposu', 12, 'location', 'TS EN 12845'],
                ['gazli-sondurme', 'Gazlı Söndürme Sistemi', 12, 'location', 'Üretici talimatı / ilgili standart'],
                ['davlumbaz-sondurme', 'Davlumbaz Söndürme Sistemi', 12, 'workplace', 'Üretici talimatı / ilgili standart'],
                ['yangin-algilama', 'Yangın Algılama ve Alarm Sistemi', 12, 'location', 'Üretici talimatı / ilgili standart'],
                ['yangin-sondurme-cihazi', 'Yangın Söndürme Cihazı (Tüp)', 12, 'workplace', 'TSE ISO/TS 11602-2'],
            ]],
            ['tezgahlar', 'Tezgahlar', 'equipment', 'pi-cog', [
                ['mekanik-pres', 'Mekanik Pres', 12, 'workplace', 'Ek-III'],
                ['hidrolik-pres', 'Hidrolik Pres', 12, 'workplace', 'Ek-III'],
                ['abkant-pres', 'Abkant Pres', 12, 'workplace', 'Ek-III'],
                ['giyotin-makas', 'Giyotin Makas', 12, 'workplace', 'Ek-III'],
                ['torna', 'Torna Tezgahı', 12, 'workplace', 'Ek-III'],
                ['freze', 'Freze Tezgahı', 12, 'workplace', 'Ek-III'],
                ['cnc', 'CNC Tezgahı', 12, 'workplace', 'Ek-III'],
                ['taslama', 'Taşlama Tezgahı', 12, 'workplace', 'Ek-III'],
                ['matkap', 'Matkap / Delme Tezgahı', 12, 'workplace', 'Ek-III'],
                ['agac-isleme', 'Ağaç İşleme Makinesi', 12, 'workplace', 'Ek-III'],
            ]],
            ['raf-kapi', 'Endüstriyel Raf ve Kapılar', 'equipment', 'pi-th-large', [
                ['depolama-rafi', 'Depolama Rafı (Palet / Drive-in)', 12, 'workplace', 'Ek-III'],
                ['endustriyel-kapi', 'Endüstriyel Kapı (Seksiyonel / Sürgülü / Otomatik)', 12, 'location', 'Ek-III'],
                ['yukleme-rampasi', 'Yükleme Rampası', 12, 'location', 'Ek-III'],
            ]],
            ['is-makineleri', 'İş Makineleri', 'equipment', 'pi-truck', [
                ['ekskavator', 'Ekskavatör', 12, 'workplace', 'Ek-III'],
                ['loder', 'Loder', 12, 'workplace', 'Ek-III'],
                ['beko-loder', 'Beko-Loder', 12, 'workplace', 'Ek-III'],
                ['dozer', 'Dozer', 12, 'workplace', 'Ek-III'],
                ['greyder', 'Greyder', 12, 'workplace', 'Ek-III'],
                ['silindir', 'Silindir', 12, 'workplace', 'Ek-III'],
                ['damperli-kamyon', 'Damperli Kamyon', 12, 'workplace', 'Ek-III'],
            ]],
        ];

        DB::transaction(function () use ($catalog) {
            foreach ($catalog as $categoryOrder => [$slug, $name, $kind, $icon, $types]) {
                $category = PeriodicEquipmentCategory::query()->updateOrCreate(
                    ['slug' => $slug],
                    ['name' => $name, 'kind' => $kind, 'icon' => $icon, 'sort_order' => $categoryOrder + 1, 'is_active' => true]
                );

                foreach ($types as $typeOrder => [$typeSlug, $typeName, $period, $scope, $note]) {
                    PeriodicEquipmentType::query()->updateOrCreate(
                        ['slug' => $typeSlug],
                        [
                            'category_id' => $category->id,
                            'name' => $typeName,
                            'default_period_months' => $period,
                            'default_scope' => $scope,
                            'regulation_note' => $note,
                            'sort_order' => $typeOrder + 1,
                            'is_active' => true,
                        ]
                    );
                }
            }
        });
    }
}
