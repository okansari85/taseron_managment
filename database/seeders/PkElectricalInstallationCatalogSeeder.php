<?php

namespace Database\Seeders;

use App\Models\PeriodicEquipmentType;
use App\Models\PeriodicInstallationSystem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * pktakip elektrik ailesi tesisatlarının sistemleri. Panolar, röleler ve ölçüm noktaları sistem bileşenidir, Ekipmanlar'a
 * kayıt açılmaz: sistemlere ekipman türü bağlanmaz. Yangın kataloğuna (PkInstallationCatalogSeeder) dokunmaz.
 * - Elektrik İç Tesisatı: Bakanlığın ZPKR02 raporundaki bölümler (eski formatta ayrı formlar).
 * - Topraklama Tesisatı (ayrı tesisat, ayrı rapor): Bakanlığın ZPKR01 raporundaki bölümler; eski formatta kontrol maddeleri
 *   ve topraklama ölçüm tablosu.
 * - Her ikisinde "Tesis Özellikleri": tesisat raporundaki tesis bilgileri, genel bilgilerin altında bilgi kartı
 *   (PkInstallationService::FACILITY_SYSTEM_SLUGS).
 * - Yangın algılama, havalandırma ve klima, paratoner, akümülatör ve trafo merkezi aynı mantıkla.
 * İdempotent: slug üzerinden updateOrCreate. Çalıştırma: php artisan db:seed --class=PkElectricalInstallationCatalogSeeder
 */
class PkElectricalInstallationCatalogSeeder extends Seeder
{
    // tür slug'ı => [[sistem slug'ı, ad], ...] (sıra katalog sırası)
    private const SYSTEMS = [
        'elektrik-ic-tesisati' => [
            ['elk-panolar', 'Panolar'],
            ['elk-termal-olcumler', 'Termal Ölçümler'],
            ['elk-fonksiyon-testleri', 'Fonksiyon Testleri ve Kaçak Akım Röleleri'],
            ['elk-potansiyel-dengeleme', 'Potansiyel Dengeleme'],
            ['elk-zemin-izolasyonu', 'Zemin İzolasyonu'],
            ['elk-tesis-ozellikleri', 'Tesis Özellikleri'],
        ],
        'topraklama' => [
            ['toprak-olcumleri', 'Topraklama Ölçümleri'],
            ['toprak-rcd-selektivite', 'RCD Selektivite Kontrolü'],
            ['toprak-gozle-kontroller', 'Gözle Kontroller'],
            ['toprak-tesis-ozellikleri', 'Tesis Özellikleri'],
        ],
        // Yangın algılama: diğer sistemleri (santral, dedektörler, butonlar, uyarı, anons, gaz, güç kaynağı, belge / proje
        // kartları) yangın kataloğu (PkInstallationCatalogSeeder) ekler; burada yalnızca ZPKR04'e göre eklenenler, onların
        // ardından (sıra no üçüncü değer).
        'yangin-algilama' => [
            ['algilama-sondurme-kontrol', 'Söndürme Sistemi Kontrolü', 10],
            ['algilama-senaryo', 'Yangın Senaryosu ve Kontrol Fonksiyonları', 11],
            ['algilama-tesis-ozellikleri', 'Tesis Özellikleri', 12],
        ],
        // Havalandırma ve klima (Bakanlığın standart formatı yok; kriter projeye uygunluk, firma formatları). Proje Bilgileri ve
        // Tesis Özellikleri bilgi kartıdır.
        'havalandirma-klima' => [
            ['hvac-proje-bilgileri', 'Proje Bilgileri'],
            ['hvac-klima-santralleri', 'Klima Santralleri'],
            ['hvac-fanlar', 'Fanlar ve Aspiratörler'],
            ['hvac-kanallar', 'Hava Kanalları ve Menfezler'],
            ['hvac-filtreler', 'Filtreler'],
            ['hvac-debi-olcumleri', 'Debi ve Hava Hızı Ölçümleri'],
            ['hvac-lokal', 'Lokal Havalandırma ve Davlumbazlar'],
            ['hvac-split-vrf', 'Split ve VRF Klimalar'],
            ['hvac-tesis-ozellikleri', 'Tesis Özellikleri'],
        ],
        // Paratoner / yıldırımdan korunma (Bakanlığın ZPKR03 formatı; rapor her yıldırımdan korunma tesisatı için ayrı).
        'paratoner' => [
            ['paratoner-yakalama', 'Yakalama Sistemi'],
            ['paratoner-indirme', 'İndirme İletkenleri'],
            ['paratoner-topraklama', 'Topraklama ve Ölçümler'],
            ['paratoner-parafudr', 'Parafudr (Dolaylı Koruma)'],
            ['paratoner-potansiyel', 'Potansiyel Dengeleme'],
            ['paratoner-tesis-ozellikleri', 'Tesis Özellikleri'],
        ],
        // Akümülatör / UPS (Bakanlığın standart formatı yok; firma formatları).
        'akumulator' => [
            ['aku-gruplari', 'Akü Grupları ve Redresörler'],
            ['aku-ups', 'UPS Sistemleri'],
            ['aku-odasi', 'Akü Odası ve Şarj Alanı'],
            ['aku-olcumleri', 'Akü Ölçümleri'],
            ['aku-tesis-ozellikleri', 'Tesis Özellikleri'],
        ],
        // Trafo merkezi (Bakanlığın ZPKK05 / ZPKR05 kriterleri: 1-36 kV transformatör gözle kontrol ve topraklama; rapor her ekipman
        // — trafo, kesici, hücre — için ayrı düzenlenebilir).
        'trafo' => [
            ['trafo-transformatorler', 'Transformatörler'],
            ['trafo-yg-hucreleri', 'YG (OG) Hücreleri'],
            ['trafo-kesici-ayirici', 'Kesici ve Ayırıcılar'],
            ['trafo-koruma-olcu', 'Koruma ve Ölçü'],
            ['trafo-enerji-girisi', 'Enerji Girişi'],
            ['trafo-topraklama', 'Trafo Merkezi Topraklaması'],
            ['trafo-odasi', 'Trafo Odası / Bina'],
            ['trafo-guvenlik', 'Güvenlik Ekipmanları ve İşaretler'],
            ['trafo-tesis-ozellikleri', 'Tesis Özellikleri'],
        ],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            foreach (self::SYSTEMS as $typeSlug => $systems) {
                $type = PeriodicEquipmentType::query()->where('slug', $typeSlug)->first();
                if (!$type) {
                    continue;
                }
                foreach ($systems as $order => $row) {
                    [$slug, $name] = $row;
                    PeriodicInstallationSystem::query()->updateOrCreate(
                        ['slug' => $slug],
                        [
                            'installation_type_id' => $type->id,
                            'name' => $name,
                            'equipment_type_id' => null,
                            'sort_order' => $row[2] ?? $order + 1,
                            'is_active' => true,
                        ]
                    );
                }
            }
        });
    }
}
