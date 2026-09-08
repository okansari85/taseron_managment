<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\BusinessEntity;
use App\Models\Company;
use App\Models\EmergencyEquipmentInspection;
use App\Models\EmergencyEquipmentType;
use App\Models\EmergencyEquipmentTypeChecklistItem;
use App\Models\Location;
use App\Models\LocationBusinessEntity;
use App\Models\LocationEmergencyEquipment;
use App\Models\OperationalRegion;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Yangın Modülü Dashboard'unu gerçek (mock olmayan) sorgularla besleyecek DEMO veri seti.
 *
 * Bu seeder sahte bir "dashboard" üretmiyor — FireSafetyDashboardService hâlâ gerçek
 * location_emergency_equipment / emergency_equipment_inspections satırlarını sorguluyor.
 * Bu dosya sadece o tabloları realistik görünen DEMO kayıtlarla dolduruyor ki Dashboard
 * boş görünmesin. Tamamen idempotent'tir (firstOrCreate + sayım kontrolleriyle) — birden
 * fazla kez çalıştırmak yeni tekrar/kopya kayıt oluşturmaz.
 *
 * Varsayımlar (gerçek veriyle eşleşmiyorsa düzeltilmesi gerekir):
 * - "Ata Holding" adında (veya adında "Ata Holding" geçen) bir tenant zaten var kabul edildi;
 *   yoksa bu seeder yeni bir tenant OLUŞTURUR (demo'nun çalışabilmesi için).
 * - Marka adları: Burger King, Popeyes, Arby's, Sbarro, Usta Dönerci, USTA PİDECİ.
 * - hazard_class ve nace_code alanları restoran/yemek hizmeti sektörü için makul
 *   PLACEHOLDER değerlerle dolduruldu (NACE 56.10, "Az Tehlikeli") — resmi NACE↔tehlike
 *   sınıfı eşleme listesi geldiğinde bu değerler gözden geçirilmeli.
 *
 * Çalıştırma: php artisan db:seed --class=FireSafetyDemoSeeder
 */
class FireSafetyDemoSeeder extends Seeder
{
    private const BRANCH_COUNT = 10;

    private const STANDARD_CHECKLIST = [
        'Askı Aparatı Kırık',
        'Boş',
        'Eksik (Serbest Çıkış, Klips, Etiket, Emniyet Pimi)',
        'Hasarlı Tüp (Ezik, Paslı)',
        'Manometre Basınç Düşük',
        'Manometre Basınç Yüksek',
        'Mühür Yok',
        'Tekerlek Hasarı',
        'Son Kullanma Tarihi Geçmiş',
        'Dolumda',
        'Vana, Tetik Arızası',
        'Kullanma Talimatı Eksik',
        'Yangın Söndürücü Hatalı Yerde',
        'Basınç Göstergesi (Kırık, Eksik)',
        'Kullanma Talimatı Okunaklı Değil',
        'Nozul Tıkalı',
        'Manometre Basıncı Okunamıyor',
        'Hidrostatik Test Tarihi Geçmiş',
    ];

    /**
     * @var array<int, array{brand: string, district: string, city: string}>
     */
    private const BRANCHES = [
        ['brand' => 'Burger King', 'district' => 'Forum AVM Şubesi', 'city' => 'Mersin'],
        ['brand' => 'Popeyes', 'district' => 'Kızılay Şubesi', 'city' => 'Ankara'],
        ['brand' => 'Burger King', 'district' => 'Kent Meydanı Şubesi', 'city' => 'Bursa'],
        ['brand' => "Arby's", 'district' => 'Optimum AVM Şubesi', 'city' => 'İzmir'],
        ['brand' => 'Sbarro', 'district' => 'MarkAntalya Şubesi', 'city' => 'Antalya'],
        ['brand' => 'Burger King', 'district' => 'M1 AVM Şubesi', 'city' => 'Adana'],
        ['brand' => 'Popeyes', 'district' => '41 Burda AVM Şubesi', 'city' => 'Kocaeli'],
        ['brand' => 'Usta Dönerci', 'district' => 'Rüya AVM Şubesi', 'city' => 'Muğla'],
        ['brand' => 'USTA PİDECİ', 'district' => 'Mall Of İstanbul Şubesi', 'city' => 'İstanbul'],
        ['brand' => 'Burger King', 'district' => 'Ankamall Şubesi', 'city' => 'Ankara'],
    ];

    public function run(): void
    {
        $tenant = Tenant::where('name', 'like', '%Ata Holding%')->first();

        if (! $tenant) {
            $tenant = Tenant::create([
                'name' => 'Ata Holding',
                'slug' => 'ata-holding',
                'status' => true,
            ]);
            $this->command?->warn('"Ata Holding" tenant\'ı bulunamadığı için yeni oluşturuldu (id: ' . $tenant->id . '). Gerçek tenant farklı bir isimdeyse bu satırı düzeltip tekrar çalıştırın.');
        }

        $brands = collect(array_unique(array_column(self::BRANCHES, 'brand')))
            ->mapWithKeys(fn (string $name) => [
                $name => Brand::withoutGlobalScopes()->firstOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $name],
                    ['is_active' => true]
                ),
            ]);

        $equipmentType = EmergencyEquipmentType::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Yangın Tüpü'],
            [
                'description' => 'Kuru kimyevi tozlu / CO2 yangın söndürme tüpleri',
                'inspection_frequency_days' => 30,
                'is_active' => true,
            ]
        );

        if ($equipmentType->checklistItems()->count() === 0) {
            foreach (self::STANDARD_CHECKLIST as $index => $label) {
                EmergencyEquipmentTypeChecklistItem::create([
                    'equipment_type_id' => $equipmentType->id,
                    'label' => $label,
                    'sort_order' => $index + 1,
                    'is_active' => true,
                ]);
            }
            $this->command?->info('18 standart Yangın Tüpü checklist maddesi eklendi.');
        }

        $checklistItemIds = $equipmentType->checklistItems()->pluck('id')->values()->all();

        $companyEntity = BusinessEntity::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('type', 'company')
            ->first();

        if (! $companyEntity) {
            $companyEntity = BusinessEntity::create([
                'tenant_id' => $tenant->id,
                'type' => 'company',
                'name' => 'Ata Holding Restoran İşletmeleri A.Ş.',
            ]);

            Company::create([
                'name' => $companyEntity->name,
                'is_active' => true,
                'company_type' => 'corporate',
                'business_entity_id' => $companyEntity->id,
            ]);
        }

        $existingBranchCount = LocationBusinessEntity::query()
            ->join('locations', 'locations.id', '=', 'location_business_entities.location_id')
            ->where('locations.tenant_id', $tenant->id)
            ->where('location_business_entities.business_entity_id', $companyEntity->id)
            ->count();

        $toCreate = max(0, self::BRANCH_COUNT - $existingBranchCount);

        if ($toCreate === 0) {
            $this->command?->info('Bu tenant için zaten en az ' . self::BRANCH_COUNT . ' şube kaydı var, yeni şube oluşturulmadı.');
        }

        $now = Carbon::now();
        $createdEquipment = [];

        DB::transaction(function () use ($tenant, $brands, $companyEntity, $toCreate, &$createdEquipment) {
            for ($i = 0; $i < $toCreate; $i++) {
                $spec = self::BRANCHES[$i % count(self::BRANCHES)];

                $location = Location::withoutGlobalScopes()->create([
                    'tenant_id' => $tenant->id,
                    'name' => $spec['brand'] . ' - ' . $spec['district'],
                    'address' => $spec['city'] . ', Türkiye',
                    'is_active' => true,
                ]);

                $region = OperationalRegion::create([
                    'tenant_id' => $tenant->id,
                    'location_id' => $location->id,
                    'name' => $spec['district'],
                    'type' => 'branch',
                    'is_active' => true,
                ]);

                $lbe = LocationBusinessEntity::create([
                    'location_id' => $location->id,
                    'business_entity_id' => $companyEntity->id,
                    'operational_region_id' => $region->id,
                    'nace_code' => '56.10',
                    'hazard_class' => 'Az Tehlikeli',
                ]);

                $lbe->brands()->syncWithoutDetaching([$brands[$spec['brand']]->id]);

                $createdEquipment[] = $lbe;
            }
        });

        // Her yeni şubeye 2 adet "Yangın Tüpü" ekipmanı ekle ve gerçekçi bir denetim
        // geçmişi mix'i oluştur (bir kısmı güncel/uygun, bir kısmı gecikmiş, birkaçı
        // açık uygunsuzluk) — Dashboard'un stat kartları ve durum listesi boş görünmesin.
        $scenarioIndex = 0;
        foreach ($createdEquipment as $lbe) {
            for ($e = 0; $e < 2; $e++) {
                $scenario = $scenarioIndex % 5; // 0-4 döngüsü: 3 iyi, 1 yaklaşan, 1 gecikmiş/kritik
                $scenarioIndex++;

                $installDate = $now->copy()->subMonths(random_int(3, 18));

                $equipment = LocationEmergencyEquipment::create([
                    'tenant_id' => $tenant->id,
                    'location_business_entity_id' => $lbe->id,
                    'equipment_type_id' => $equipmentType->id,
                    'code' => strtoupper(str_replace([' ', "'", '-'], '', $lbe->location?->name ?? 'SUBE')) . '-KKT-' . ($e + 1),
                    'install_date' => $installDate->toDateString(),
                    'status' => 'active',
                    'is_active' => true,
                ]);

                [$inspectedAt, $issueIds] = match (true) {
                    $scenario < 3 => [$now->copy()->subDays(random_int(1, 20)), []], // güncel / uygun
                    $scenario === 3 => [$now->copy()->subDays(random_int(22, 29)), []], // yaklaşan kontrol
                    default => [$now->copy()->subDays(random_int(35, 60)), array_slice($checklistItemIds, 0, random_int(1, 2))], // gecikmiş + uygunsuzluk
                };

                $inspection = EmergencyEquipmentInspection::create([
                    'tenant_id' => $tenant->id,
                    'location_emergency_equipment_id' => $equipment->id,
                    'inspected_by_name' => 'Demo Denetçi',
                    'overall_result' => $issueIds === [] ? 'passed' : 'failed',
                    'notes' => $issueIds === [] ? null : 'Demo veri: tespit edilen sorun otomatik oluşturuldu.',
                    'inspected_at' => $inspectedAt,
                ]);

                if ($issueIds !== []) {
                    $rows = array_map(fn (int $checklistItemId) => [
                        'tenant_id' => $tenant->id,
                        'inspection_id' => $inspection->id,
                        'checklist_item_id' => $checklistItemId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $issueIds);

                    DB::table('emergency_equipment_inspection_items')->insert($rows);
                }
            }
        }

        $this->command?->info(sprintf(
            'Yangın Modülü demo verisi hazır: tenant "%s" (id: %d), %d yeni şube, %d yeni ekipman.',
            $tenant->name,
            $tenant->id,
            count($createdEquipment),
            count($createdEquipment) * 2
        ));
    }
}
