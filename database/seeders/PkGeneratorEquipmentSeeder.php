<?php

namespace Database\Seeders;

use App\Models\PeriodicEquipmentCategory;
use App\Models\PeriodicEquipmentSpec;
use App\Models\PeriodicEquipmentType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * pktakip: jeneratör bir ekipmandır (her jeneratör kendi kaydı, raporu makine başına). Katalogda "Tesisatlar" kategorisinde
 * duran Jeneratör türü kendi ekipman kategorisine ("Jeneratör") alınır; böylece Ekipmanlar'da ve rapordan ekipman tanımlarken
 * yapay zekanın kataloğunda görünür. Türün etiketleri (yakıt türü) aynen kalır; teknik özellikleri NETA jeneratör periyodik
 * kontrol raporundaki etiket bilgilerine göre eklenir (marka, model, seri no gibi ortak özellikler zaten var).
 * İdempotent. Çalıştırma: php artisan db:seed --class=PkGeneratorEquipmentSeeder
 */
class PkGeneratorEquipmentSeeder extends Seeder
{
    // [key, ad, birim, raporlardaki diğer adları]
    private const SPECS = [
        ['standby_guc', 'Standby güç', null, ['Standby Güç (kW/HP)', 'Stand-by Güç', 'Yedek Güç']],
        ['primer_guc', 'Primer güç', null, ['Primer Güç (kW/HP)', 'Prime Güç', 'Sürekli Güç']],
        ['gerilim', 'Gerilim', 'V', ['Gerilim (Volt)', 'Çıkış Gerilimi', 'Nominal Gerilim']],
        ['frekans', 'Frekans', 'Hz', ['Frekans (Hz)']],
        ['akim', 'Akım', 'A', ['Akım (Amper)', 'Nominal Akım']],
        ['faz_sayisi', 'Faz sayısı', null, []],
        ['devir_sayisi', 'Devir sayısı', 'd/dk', ['Devir Sayısı (Hız d\dk veya m\sn)', 'Devir']],
        ['montaj_sekli', 'Montaj şekli', null, []],
        ['ilk_hareket_tipi', 'İlk hareket tipi', null, ['İlk Hareket Tipi (Otomatik, Manuel)']],
        ['sogutma_tipi', 'Soğutma sistemi tipi', null, ['Soğutma Sisteminin Tipi (Sıvı Soğ, Hava Soğ.)', 'Soğutma Tipi']],
        ['kabin', 'Kabin', null, ['Ekipman Koruyucu']],
        ['jenerator_kutlesi', 'Jeneratör kütlesi', 'kg', ['Jeneratör Kütlesi (kg)', 'Ağırlık']],
        ['uretim_yili', 'Üretim yılı', null, ['İmal Tarihi', 'İmal Yılı', 'Üretim Tarihi']],
    ];

    public function run(): void
    {
        $type = PeriodicEquipmentType::query()->where('slug', 'jenerator')->first();
        if (!$type) {
            return;
        }
        DB::transaction(function () use ($type) {
            $category = PeriodicEquipmentCategory::query()->updateOrCreate(
                ['slug' => 'jenerator'],
                ['name' => 'Jeneratör', 'kind' => 'equipment', 'icon' => 'pi-bolt', 'sort_order' => 10, 'is_active' => true]
            );
            $type->update(['category_id' => $category->id, 'sort_order' => 1]);
            foreach (self::SPECS as $order => [$key, $name, $unit, $aliases]) {
                PeriodicEquipmentSpec::query()->updateOrCreate(
                    ['equipment_type_id' => $type->id, 'key' => $key],
                    ['name' => $name, 'unit' => $unit, 'aliases' => $aliases, 'sort_order' => $order + 1, 'is_active' => true]
                );
            }
        });
    }
}
