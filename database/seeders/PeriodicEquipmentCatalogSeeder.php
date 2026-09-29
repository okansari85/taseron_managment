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
                ['buhar-kazani', 'Buhar Kazanı', 12, 'location', 'Ek-III Tablo-1', ['Doğalgaz', 'Fuel-oil', 'Katı yakıt', 'Elektrikli'], 'Yakıt türü'],
                ['kalorifer-kazani', 'Kalorifer Kazanı', 12, 'location', 'Ek-III Tablo-1', ['Doğalgaz', 'Fuel-oil', 'Katı yakıt', 'Elektrikli'], 'Yakıt türü'],
                ['hidrofor', 'Hidrofor', 12, 'location', 'Ek-III Tablo-1', ['Membranlı', 'Membransız'], 'Tank tipi'],
                ['genlesme-tanki', 'Genleşme Tankı', 12, 'location', 'Ek-III Tablo-1', ['Kapalı (membranlı)', 'Açık'], 'Tip'],
                ['boyler', 'Boyler', 12, 'location', 'Ek-III Tablo-1', ['Serpantinli', 'Elektrikli'], 'Isıtma türü'],
                ['basincli-hava-tanki', 'Basınçlı Hava Tankı (Kompresör)', 12, 'workplace', 'Ek-III Tablo-1', ['Pistonlu', 'Vidalı'], 'Kompresör tipi'],
                ['otoklav', 'Otoklav', 12, 'workplace', 'Ek-III Tablo-1', ['Sterilizasyon', 'Endüstriyel'], 'Kullanım'],
                ['lpg-tanki', 'LPG Tankı (Yerüstü / Yeraltı)', 120, 'location', 'Ek-III Tablo-1 (10 yıl)', ['Yerüstü', 'Yeraltı'], 'Konum'],
                ['lpg-tupu', 'LPG Tüpü (Kullanımdaki)', 12, 'workplace', 'Ek-III Tablo-1', ['12 kg', '24 kg', '45 kg'], 'Kapasite'],
                ['tasinabilir-gaz-tupu', 'Taşınabilir Gaz Tüpü', 36, 'workplace', 'Ek-III Tablo-1 (3 yıl)', ['Oksijen', 'Azot', 'Argon', 'CO2', 'Karışım'], 'Gaz'],
                ['asetilen-tupu', 'Asetilen Tüpü', null, 'workplace', 'TS EN ISO 10462'],
                ['manifoldlu-tup-demeti', 'Manifoldlu Tüp Demeti', 12, 'workplace', 'Ek-III Tablo-1', ['Oksijen', 'Azot', 'Argon', 'CO2', 'Hidrojen'], 'Gaz'],
                ['kriyojenik-tank', 'Kriyojenik Tank', null, 'location', 'TS EN ISO 21009-2', ['Sıvı azot', 'Sıvı oksijen', 'Sıvı argon', 'Sıvı CO2'], 'Gaz'],
                ['tehlikeli-sivi-tanki', 'Tehlikeli Sıvı Tankı / Deposu', 120, 'location', 'Ek-III Tablo-1 (10 yıl)', ['Yerüstü', 'Yeraltı'], 'Konum'],
            ]],
            ['kaldirma-iletme', 'Kaldırma ve İletme Ekipmanları', 'equipment', 'pi-arrow-up', [
                ['forklift', 'Forklift', 12, 'workplace', 'Ek-III Tablo-2', ['Dizel', 'Elektrikli', 'LPG'], 'Yakıt türü'],
                ['istif-makinesi', 'İstif Makinesi', 12, 'workplace', 'Ek-III Tablo-2', ['Elektrikli', 'Manuel'], 'Tahrik türü'],
                ['transpalet', 'Transpalet', 12, 'workplace', 'Ek-III Tablo-2', ['Elektrikli', 'Manuel'], 'Tahrik türü'],
                ['kopru-vinc', 'Köprülü Vinç', 12, 'workplace', 'Ek-III Tablo-2', ['Tek kirişli', 'Çift kirişli'], 'Kiriş tipi'],
                ['portal-vinc', 'Portal Vinç', 12, 'workplace', 'Ek-III Tablo-2', ['Tam portal', 'Yarım portal'], 'Tip'],
                ['kule-vinc', 'Kule Vinç', 12, 'workplace', 'Ek-III Tablo-2', ['Çekiç başlı', 'Düz başlı', 'Bayraklı (luffing)'], 'Kule tipi'],
                ['mobil-vinc', 'Mobil Vinç', 12, 'workplace', 'Ek-III Tablo-2', ['Kamyon üstü', 'Paletli', 'Arazi tipi', 'Hiyap'], 'Tip'],
                ['caraskal', 'Caraskal / Vinç Arabası', 12, 'workplace', 'Ek-III Tablo-2', ['Elektrikli', 'Manuel', 'Pnömatik'], 'Tahrik türü'],
                ['arac-kaldirma-lifti', 'Araç Kaldırma Lifti', 12, 'workplace', 'Ek-III Tablo-2', ['İki sütunlu', 'Dört sütunlu', 'Makaslı'], 'Tip'],
                ['makasli-platform', 'Makaslı Platform', 12, 'workplace', 'Ek-III Tablo-2', ['Elektrikli', 'Dizel'], 'Tahrik türü'],
                ['sepetli-platform', 'Sepetli Platform', 12, 'workplace', 'Ek-III Tablo-2', ['Araç üstü', 'Eklemli', 'Teleskopik'], 'Tip'],
                ['yuk-asansoru', 'Yük / Servis Asansörü', 12, 'location', 'Ek-III Tablo-2', ['Halatlı (elektrikli)', 'Hidrolik'], 'Tahrik türü'],
                ['yuruyen-merdiven', 'Yürüyen Merdiven / Bant', 12, 'location', 'Ek-III Tablo-2', ['Yürüyen merdiven', 'Yürüyen bant'], 'Tip'],
                ['konveyor', 'Konveyör', 12, 'workplace', 'Ek-III Tablo-2', ['Bantlı', 'Rulolu', 'Zincirli', 'Helezon'], 'Tip'],
                ['kaldirma-aksesuari', 'Kaldırma Aksesuarları (Zincir, Sapan, Kanca)', 12, 'workplace', 'Ek-III Tablo-2', ['Zincir', 'Tekstil sapan', 'Çelik halat', 'Kanca', 'Kelepçe'], 'Tip'],
                ['yapi-iskelesi', 'Yapı İskelesi', 6, 'workplace', 'Ek-III Tablo-2 (6 ay)', ['Sabit', 'Seyyar (tekerlekli)'], 'Tip'],
                ['cephe-iskelesi', 'Cephe İskelesi (Asılı)', 6, 'workplace', 'Ek-III Tablo-2 (6 ay)', ['Elektrikli', 'Manuel'], 'Tahrik türü'],
            ]],
            ['asansorler', 'Asansörler', 'equipment', 'pi-sort-alt', [
                ['insan-asansoru', 'İnsan Asansörü', 12, 'location', 'Asansör Periyodik Kontrol Yönetmeliği', ['Halatlı (elektrikli)', 'Hidrolik'], 'Tahrik türü'],
                ['yuk-asansoru-insan-tasimali', 'İnsan Taşımalı Yük Asansörü', 12, 'location', 'Asansör Periyodik Kontrol Yönetmeliği', ['Halatlı (elektrikli)', 'Hidrolik'], 'Tahrik türü'],
                ['engelli-platformu', 'Engelli Kaldırma Platformu', 12, 'location', 'Asansör Periyodik Kontrol Yönetmeliği', ['Dikey', 'Merdiven (eğik)'], 'Tip'],
            ]],
            ['tesisatlar', 'Tesisatlar', 'installation', 'pi-bolt', [
                ['elektrik-ic-tesisati', 'Elektrik İç Tesisatı', 12, 'location', 'Ek-III Tablo-3'],
                ['topraklama', 'Topraklama Tesisatı', 12, 'location', 'Ek-III Tablo-3', ['Koruma', 'İşletme'], 'Tür'],
                ['paratoner', 'Paratoner (Yıldırımdan Korunma)', 12, 'location', 'Ek-III Tablo-3', ['Aktif (erken akış)', 'Pasif (Faraday / Franklin)'], 'Tip'],
                ['trafo', 'Trafo / Transformatör', 12, 'location', 'Ek-III Tablo-3', ['Yağlı', 'Kuru'], 'Tip'],
                ['akumulator', 'Akümülatör / UPS', 12, 'location', 'Ek-III Tablo-3', ['Akü grubu', 'UPS'], 'Tip'],
                ['jenerator', 'Jeneratör', 12, 'location', 'Ek-III Tablo-3', ['Dizel', 'Doğalgaz', 'Benzinli'], 'Yakıt türü'],
                ['havalandirma-klima', 'Havalandırma ve Klima Tesisatı', 12, 'location', 'Ek-III Tablo-3', ['Merkezi (santral)', 'Split / VRF'], 'Tip'],
                ['dogalgaz-tesisati', 'Doğalgaz / Boru Tesisatı', 12, 'location', 'Ek-III Tablo-3', ['Doğalgaz', 'LPG', 'Basınçlı hava', 'Buhar'], 'Akışkan'],
                ['katodik-koruma', 'Katodik Koruma Tesisatı', 12, 'location', 'Ek-III Tablo-3', ['Galvanik (kurban anot)', 'Dış akım'], 'Tip'],
                ['yuksek-gerilim', 'Yüksek Gerilim Tesisatı', 12, 'location', 'Ek-III Tablo-3'],
            ]],
            ['yangin-ekipmanlari', 'Yangın Ekipmanları', 'equipment', 'pi-shield', [
                // Tüpler lokasyonun tamamına kayıtlıdır (firma bazında değil).
                ['yangin-sondurme-cihazi', 'Yangın Söndürme Cihazı (Tüp)', 12, 'location','TSE ISO/TS 11602-2', ['KKT', 'CO2', 'Köpük', 'Su', 'Islak kimyasal', 'Temiz gazlı'], 'Söndürücü'],
                ['yangin-dolabi', 'Yangın Dolabı / Hortum Makarası', 12, 'location', 'TS EN 671-3', ['Yarı sert (makaralı)', 'Yassı'], 'Hortum'],
                ['yangin-pompasi', 'Yangın Pompası', 12, 'location', 'TS EN 12845', ['Elektrikli', 'Dizel', 'Jokey'], 'Tip'],
                ['hidrant', 'Hidrant', 12, 'location', 'TS 9811', ['Yerüstü', 'Yeraltı'], 'Tip'],
            ]],
            ['yangin-sistemleri', 'Yangın Sistemleri', 'installation', 'pi-shield', [
                ['sprinkler', 'Sprinkler Sistemi', 12, 'location', 'TS EN 12845', ['Islak', 'Kuru', 'Ön tepkili', 'Baskın'], 'Sistem'],
                ['yangin-su-deposu', 'Yangın Su Deposu', 12, 'location', 'TS EN 12845', ['Betonarme', 'Çelik (prefabrik)'], 'Malzeme'],
                ['gazli-sondurme', 'Gazlı Söndürme Sistemi', 12, 'location', 'Üretici talimatı / ilgili standart', ['FM-200', 'Novec 1230', 'CO2', 'Inert (IG-541)'], 'Gaz'],
                ['davlumbaz-sondurme', 'Davlumbaz Söndürme Sistemi', 12, 'workplace', 'Üretici talimatı / ilgili standart'],
                ['yangin-algilama', 'Yangın Algılama ve Uyarı Sistemleri', 12, 'location', 'Üretici talimatı / ilgili standart', ['Konvansiyonel', 'Adresli'], 'Tip'],
            ]],
            ['tezgahlar', 'Tezgahlar', 'equipment', 'pi-cog', [
                ['mekanik-pres', 'Mekanik Pres', 12, 'workplace', 'Ek-III', ['Friksiyonlu (pnömatik)', 'Kamalı (mekanik)'], 'Kavrama tipi'],
                ['hidrolik-pres', 'Hidrolik Pres', 12, 'workplace', 'Ek-III', ['C gövde', 'H gövde (kolonlu)'], 'Gövde'],
                ['abkant-pres', 'Abkant Pres', 12, 'workplace', 'Ek-III', ['Hidrolik', 'Mekanik', 'Elektrikli (servo)'], 'Tahrik türü'],
                ['giyotin-makas', 'Giyotin Makas', 12, 'workplace', 'Ek-III', ['Hidrolik', 'Mekanik'], 'Tahrik türü'],
                ['torna', 'Torna Tezgahı', 12, 'workplace', 'Ek-III'],
                ['freze', 'Freze Tezgahı', 12, 'workplace', 'Ek-III', ['Dik', 'Yatay', 'Üniversal'], 'Tip'],
                ['cnc', 'CNC Tezgahı', 12, 'workplace', 'Ek-III', ['Torna', 'İşleme merkezi', 'Lazer', 'Plazma', 'Router'], 'Tip'],
                ['taslama', 'Taşlama Tezgahı', 12, 'workplace', 'Ek-III', ['Satıh', 'Silindirik', 'Taş motoru'], 'Tip'],
                ['matkap', 'Matkap / Delme Tezgahı', 12, 'workplace', 'Ek-III', ['Sütunlu', 'Radyal', 'Masa tipi'], 'Tip'],
                ['agac-isleme', 'Ağaç İşleme Makinesi', 12, 'workplace', 'Ek-III', ['Daire testere', 'Şerit testere', 'Planya', 'Kalınlık', 'Freze'], 'Tip'],
            ]],
            ['raf-kapi', 'Endüstriyel Raf ve Kapılar', 'equipment', 'pi-th-large', [
                ['depolama-rafi', 'Depolama Rafı (Palet / Drive-in)', 12, 'workplace', 'Ek-III', ['Selektif (palet)', 'Drive-in', 'Konsol', 'Mobil'], 'Tip'],
                ['endustriyel-kapi', 'Endüstriyel Kapı (Seksiyonel / Sürgülü / Otomatik)', 12, 'location', 'Ek-III', ['Seksiyonel', 'Sürgülü', 'Hızlı (otomatik)', 'Kepenk'], 'Tip'],
                ['yukleme-rampasi', 'Yükleme Rampası', 12, 'location', 'Ek-III', ['Hidrolik', 'Mekanik'], 'Tahrik türü'],
            ]],
            ['is-makineleri', 'İş Makineleri', 'equipment', 'pi-truck', [
                ['ekskavator', 'Ekskavatör', 12, 'workplace', 'Ek-III', ['Paletli', 'Lastik tekerlekli'], 'Yürüyüş'],
                ['loder', 'Loder', 12, 'workplace', 'Ek-III', ['Lastik tekerlekli', 'Paletli'], 'Yürüyüş'],
                ['beko-loder', 'Beko-Loder', 12, 'workplace', 'Ek-III'],
                ['dozer', 'Dozer', 12, 'workplace', 'Ek-III', ['Paletli', 'Lastik tekerlekli'], 'Yürüyüş'],
                ['greyder', 'Greyder', 12, 'workplace', 'Ek-III'],
                ['silindir', 'Silindir', 12, 'workplace', 'Ek-III', ['Tek bilyalı', 'Çift bilyalı', 'Lastik tekerlekli'], 'Tip'],
                ['damperli-kamyon', 'Damperli Kamyon', 12, 'workplace', 'Ek-III', ['Yol tipi', 'Arazi (maden) tipi'], 'Tip'],
            ]],
        ];

        DB::transaction(function () use ($catalog) {
            foreach ($catalog as $categoryOrder => [$slug, $name, $kind, $icon, $types]) {
                $category = PeriodicEquipmentCategory::query()->updateOrCreate(
                    ['slug' => $slug],
                    ['name' => $name, 'kind' => $kind, 'icon' => $icon, 'sort_order' => $categoryOrder + 1, 'is_active' => true]
                );

                // 6-7. eleman (isteğe bağlı): etiket seçenekleri ve etiketin adı, örn. Forklift: Dizel / Elektrikli, "Yakıt türü".
                foreach ($types as $typeOrder => $type) {
                    [$typeSlug, $typeName, $period, $scope, $note] = $type;
                    PeriodicEquipmentType::query()->updateOrCreate(
                        ['slug' => $typeSlug],
                        [
                            'category_id' => $category->id,
                            'name' => $typeName,
                            'default_period_months' => $period,
                            'default_scope' => $scope,
                            'regulation_note' => $note,
                            'variants' => $type[5] ?? null,
                            'variant_label' => $type[6] ?? null,
                            'sort_order' => $typeOrder + 1,
                            'is_active' => true,
                        ]
                    );
                }
            }
        });
    }
}
