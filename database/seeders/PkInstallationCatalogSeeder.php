<?php

namespace Database\Seeders;

use App\Models\PeriodicEquipmentCategory;
use App\Models\PeriodicEquipmentType;
use App\Models\PeriodicInstallationSystem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * pktakip tesisat kataloğu: tesisat türü (periodic_equipment_types, kind=installation) ve içindeki sistemler.
 * Yangın Tesisatı ve Yangın Algılama ve Uyarı Sistemleri (ayrı tesisat). Sistem → ekipman türleri (ara tablo): Ekipmanlar'daki
 * bu türlerdeki kayıtlar sistemin altında görünür. Yangın tesisatı ekipman türleri Ekipmanlar'da "Yangın Ekipmanları"
 * kategorisinde, diğer türler gibi (tesisat adı geçmez).
 * Tesisat raporu okunurken bu liste yapay zekaya verilir; yapay zeka türü seçer ve sistemleri bu adlarla yazar.
 * İdempotent: slug üzerinden updateOrCreate; mevcut türlere dokunmaz (yalnızca yeni türleri ekler).
 */
class PkInstallationCatalogSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $category = PeriodicEquipmentCategory::query()->where('slug', 'yangin-sistemleri')->first();
            if (!$category) {
                return;
            }
            $installation = PeriodicEquipmentType::query()->updateOrCreate(
                ['slug' => 'yangin-tesisati'],
                [
                    'category_id' => $category->id,
                    'name' => 'Yangın Tesisatı',
                    'default_period_months' => 12,
                    'default_scope' => 'location',
                    'regulation_note' => 'Binaların Yangından Korunması Hakkında Yönetmelik; TS EN 12845, TS EN 671, TS 9811',
                    'sort_order' => 0,
                    'is_active' => true,
                ]
            );
            $equipment = fn (string $slug) => PeriodicEquipmentType::query()->where('slug', $slug)->value('id');
            // Kullanımdan kalkanlar (silinmez): eski birleşik "Genel (Belge, Proje ve Kayıtlar)" sistemi ikiye ayrıldı;
            // tüpler tesisatta değil (raporu ayrı, ayrı firma, toplu kontrol), yalnızca Ekipmanlar'da.
            PeriodicInstallationSystem::query()->whereIn('slug', ['genel', 'algilama-genel', 'portatif-sondurucu'])->update(['is_active' => false]);

            $this->fireEquipmentTypes();

            // [slug, ad, ekipman türleri]
            $systems = [
                ['belge-kayit', 'Belge ve Kayıt Kontrolleri', []],
                ['proje-bilgileri', 'Proje Bilgileri', []],
                ['yangin-pompa-istasyonu', 'Yangın Pompa İstasyonu', ['yangin-pompasi', 'pompa-kontrol-panosu', 'basinc-tanki']],
                ['yangin-su-deposu', 'Yangın Su Deposu', ['su-deposu']],
                ['yangin-dolabi-sistemi', 'Yangın Dolapları ve Hortum Makaraları', ['yangin-dolabi']],
                ['hidrant-sistemi', 'Hidrant Sistemi', ['hidrant', 'hidrant-kabini']],
                ['sprinkler', 'Sprinkler (Yağmurlama) Sistemi', ['sprinkler-vana-istasyonu', 'zon-kontrol-vanasi']],
                ['sabit-boru', 'Sabit Boru Tesisatı ve İtfaiye Bağlantıları', ['itfaiye-su-verme-agzi', 'yangin-kolonu']],
                ['kopuklu-sondurme', 'Köpüklü Söndürme Sistemi', ['kopuk-konsantre-tanki', 'kopuk-oranlayici']],
                ['gazli-sondurme', 'Gazlı Söndürme Sistemi', ['gazli-sondurme-tupu', 'sondurme-kontrol-paneli']],
                ['davlumbaz-sondurme', 'Davlumbaz Söndürme Sistemi', ['davlumbaz-sondurme-unitesi']],
                ['duman-kontrol', 'Duman Kontrol ve Basınçlandırma (Yangın Havalandırması)', ['duman-egzoz-fani', 'basinclandirma-fani', 'duman-damperi']],
            ];
            $this->systems($installation, $systems, $equipment);

            // Yangın algılama ve uyarı: katalogdaki mevcut tür (ana katalog seeder'ı) — ayrı tesisat.
            $detection = PeriodicEquipmentType::query()->where('slug', 'yangin-algilama')->first();
            if ($detection) {
                $detection->update(['name' => 'Yangın Algılama ve Uyarı Sistemleri', 'default_scope' => 'location', 'sort_order' => 1]);
                // Ekipman türleri, algılama sekmesi açılınca ayrıca eklenecek.
                $this->systems($detection, [
                    ['algilama-belge-kayit', 'Belge ve Kayıt Kontrolleri', []],
                    ['algilama-proje-bilgileri', 'Proje Bilgileri', []],
                    ['yangin-alarm-santrali', 'Yangın Alarm Santrali (Kontrol Paneli)', []],
                    ['dedektorler', 'Dedektörler (Duman, Isı, Alev)', []],
                    ['ihbar-butonlari', 'Yangın İhbar Butonları', []],
                    ['sesli-isikli-uyari', 'Sesli ve Işıklı Uyarı Cihazları (Siren, Flaşör)', []],
                    ['acil-anons', 'Acil Anons / Sesli Tahliye Sistemi', []],
                    ['gaz-algilama', 'Gaz Algılama Sistemi', []],
                    ['guc-kaynagi', 'Güç Kaynağı ve Aküler', []],
                ], $equipment);
            }
        });
    }

    /**
     * Yangın tesisatı sistemlerindeki ekipman türleri: Ekipmanlar'da "Yangın Ekipmanları" kategorisinde, diğer türler gibi
     * (mevcut Tüp, Dolap, Pompa, Hidrant'tan sonra). [slug, ad, varsayılan kapsam, mevzuat, etiketler, etiket adı]
     */
    private function fireEquipmentTypes(): void
    {
        $category = PeriodicEquipmentCategory::query()->where('slug', 'yangin-ekipmanlari')->first();
        if (!$category) {
            return;
        }
        $types = [
            ['pompa-kontrol-panosu', 'Pompa Kontrol Panosu', 'location', 'TS EN 12845'],
            ['basinc-tanki', 'Basınç (Hidrofor) Tankı', 'location', 'TS EN 12845'],
            ['su-deposu', 'Yangın Su Deposu', 'location', 'TS EN 12845', ['Betonarme', 'Çelik (prefabrik)'], 'Malzeme'],
            ['hidrant-kabini', 'Hidrant Kabini', 'location', 'TS 9811'],
            ['sprinkler-vana-istasyonu', 'Sprinkler Vana İstasyonu', 'location', 'TS EN 12845', ['Islak', 'Kuru', 'Ön tepkili', 'Baskın'], 'Sistem'],
            ['zon-kontrol-vanasi', 'Kat / Zon Kontrol Vanası', 'location', 'TS EN 12845'],
            ['itfaiye-su-verme-agzi', 'İtfaiye Su Verme Ağzı', 'location', 'Binaların Yangından Korunması Hakkında Yönetmelik', ['İkiz', 'Tekli'], 'Tip'],
            ['yangin-kolonu', 'Yangın Kolonu', 'location', 'Binaların Yangından Korunması Hakkında Yönetmelik', ['Islak', 'Kuru'], 'Tip'],
            ['kopuk-konsantre-tanki', 'Köpük Konsantre Tankı', 'location', 'TS EN 13565-2'],
            ['kopuk-oranlayici', 'Köpük Oranlayıcı', 'location', 'TS EN 13565-1'],
            ['gazli-sondurme-tupu', 'Gazlı Söndürme Tüpü', 'location', 'TS EN 15004', ['FM-200', 'Novec 1230', 'CO2', 'Inert (IG-541)'], 'Gaz'],
            ['sondurme-kontrol-paneli', 'Söndürme Kontrol Paneli', 'location', 'TS EN 12094-1'],
            ['davlumbaz-sondurme-unitesi', 'Davlumbaz Söndürme Ünitesi', 'workplace', 'TS EN 17446'],
            ['duman-egzoz-fani', 'Duman Egzoz Fanı', 'location', 'TS EN 12101-3'],
            ['basinclandirma-fani', 'Basınçlandırma Fanı', 'location', 'TS EN 12101-6'],
            ['duman-damperi', 'Duman / Yangın Damperi', 'location', 'TS EN 12101-8, TS EN 15650', ['Duman damperi', 'Yangın damperi'], 'Tip'],
        ];
        foreach ($types as $order => $type) {
            [$slug, $name, $scope, $note] = $type;
            PeriodicEquipmentType::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'category_id' => $category->id,
                    'name' => $name,
                    'default_period_months' => 12,
                    'default_scope' => $scope,
                    'regulation_note' => $note,
                    'variants' => $type[4] ?? null,
                    'variant_label' => $type[5] ?? null,
                    'sort_order' => 10 + $order,
                    'is_active' => true,
                ]
            );
        }
    }

    // [slug, ad, ekipman türleri]: sistem + ekipman türleri (katalogdakiyle eşitlenir; ilk tür equipment_type_id'de de durur).
    private function systems(PeriodicEquipmentType $installation, array $systems, callable $equipment): void
    {
        foreach ($systems as $order => [$slug, $name, $equipmentSlugs]) {
            $typeIds = collect($equipmentSlugs)->map(fn (string $equipmentSlug) => (int) $equipment($equipmentSlug))->filter()->values()->all();
            $system = PeriodicInstallationSystem::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'installation_type_id' => $installation->id,
                    'name' => $name,
                    'equipment_type_id' => $typeIds[0] ?? null,
                    'sort_order' => $order + 1,
                    'is_active' => true,
                ]
            );
            // İlk tesisat migration'ı bu seeder'ı ara tablo oluşmadan çalıştırır.
            if (Schema::hasTable('periodic_system_equipment_types')) {
                $system->equipmentTypes()->sync(collect($typeIds)->mapWithKeys(fn (int $id, int $index) => [$id => ['sort_order' => $index + 1]])->all());
            }
        }
    }
}
