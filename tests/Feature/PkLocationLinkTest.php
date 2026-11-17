<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SuperAdminExpertOnboardingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * PkLocationLinkController: kayıtlı lokasyonu ara, ana firmanın düğümüne bağla, ortak lokasyonu yalnız bu ana firmadan ayır.
 * Bellekteki sqlite; müşteri / organizasyon / lokasyon tabloları yalnız gereken kolonlarla kurulur (PkFindingTest'teki gibi).
 */
class PkLocationLinkTest extends TestCase
{
    private const MIGRATIONS = [
        '0001_01_01_000000_create_users_table.php',
        '2026_08_17_143227_create_tenants_table.php',
        '2026_08_17_212633_create_permission_tables.php',
        '2026_08_21_000000_add_logo_path_to_tenants_table.php',
        '2026_09_03_000001_create_user_scopes_table.php',
        '2026_09_04_000002_add_contractor_id_to_users_table.php',
        '2026_09_04_000003_add_is_expert_to_users_table.php',
        '2026_09_05_000002_add_featured_brand_id_to_tenants_table.php',
        '2026_09_07_000005_add_operational_area_enabled_to_tenants_table.php',
        '2026_09_07_000008_add_location_view_mode_to_tenants_table.php',
        '2026_09_22_000001_add_tenant_type_to_tenants_table.php',
        '2026_09_22_140000_create_expert_invitations_table.php',
        '2026_09_29_100000_create_pk_account_users_table.php',
        '2026_10_03_130000_create_pk_billing_tables.php',
        '2026_10_10_090000_add_workplace_limit_to_pk_billing.php',
        '2026_11_07_100000_create_pk_payment_orders_table.php',
        '2026_11_07_110000_add_paid_until_and_agreements_version.php',
    ];

    private int $tenantId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlite.foreign_key_constraints' => false]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--path' => array_map(fn ($f) => 'database/migrations/' . $f, self::MIGRATIONS)]);
        Role::findOrCreate('tenant', 'web');
        Role::findOrCreate('isg', 'web');
        Mail::fake();
        foreach (['cities', 'districts'] as $table) {
            Schema::create($table, fn (Blueprint $t) => [$t->id(), $t->string('name')]);
        }
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('organizations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->string('name');
            $t->string('type')->nullable();
            $t->timestamps();
        });
        Schema::create('customer_organization', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('customer_id');
            $t->unsignedBigInteger('organization_id');
            $t->timestamps();
        });
        Schema::create('locations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->string('name');
            $t->string('address')->nullable();
            $t->unsignedBigInteger('city_id')->nullable();
            $t->unsignedBigInteger('district_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('business_entities', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('type');
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->unsignedBigInteger('business_entity_id');
            $t->string('name');
            $t->string('short_name')->nullable();
            $t->text('description')->nullable();
            $t->string('company_type')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('location_business_entities', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('location_id');
            $t->unsignedBigInteger('business_entity_id');
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->unsignedBigInteger('sub_facility_id')->nullable();
            $t->string('hazard_class')->nullable();
            $t->string('nace_code')->nullable();
            $t->string('activity')->nullable();
            $t->string('sgk_workplace_number')->nullable();
            $t->string('address')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('sub_facilities', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('location_id');
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('organization_locations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('location_id');
            $t->timestamps();
        });

        Schema::create('pk_workplace_names', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('location_business_entity_id');
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('location_experts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('location_business_entity_id');
            $t->unsignedBigInteger('user_id');
            $t->timestamps();
        });

        $result = app(SuperAdminExpertOnboardingService::class)->create('Uzman', 'Uzman Kişi', 'uzman@example.test');
        $this->tenantId = (int) $result['tenant']->id;
        $this->user = User::query()->findOrFail($result['user']['id']);
    }

    private function api()
    {
        return $this->actingAs($this->user, 'sanctum')->withHeader('X-Tenant-ID', (string) $this->tenantId);
    }

    // Ana firma + kök düğüm + bir dal; [müşteri, kök, dal]
    private function customer(string $name): array
    {
        $customer = DB::table('customers')->insertGetId(['tenant_id' => $this->tenantId, 'name' => $name]);
        $root = DB::table('organizations')->insertGetId(['tenant_id' => $this->tenantId, 'name' => $name]);
        $branch = DB::table('organizations')->insertGetId(['tenant_id' => $this->tenantId, 'parent_id' => $root, 'name' => "{$name} Dal"]);
        DB::table('customer_organization')->insert(['customer_id' => $customer, 'organization_id' => $root]);

        return [$customer, $root, $branch];
    }

    public function test_candidates_link_and_detach(): void
    {
        [$a, $aRoot, $aBranch] = $this->customer('A Holding');
        [$b, $bRoot] = $this->customer('B Holding');
        $free = DB::table('locations')->insertGetId(['tenant_id' => $this->tenantId, 'name' => 'Serbest Depo']);
        $ofB = DB::table('locations')->insertGetId(['tenant_id' => $this->tenantId, 'name' => 'Ortak Kampüs']);
        DB::table('organization_locations')->insert(['organization_id' => $bRoot, 'location_id' => $ofB]);
        $foreignTenant = DB::table('tenants')->insertGetId(['name' => 'Başka', 'slug' => 'baska']);
        DB::table('locations')->insert(['tenant_id' => $foreignTenant, 'name' => 'Başka Hesabın Deposu']);

        // A'ya bağlı olmayan, bu hesaptaki iki lokasyon; başka hesabınki yok.
        $this->api()->getJson("/api/customers/{$a}/pk-location-candidates")->assertOk()->assertJsonCount(2)
            ->assertJsonPath('0.name', 'Ortak Kampüs')->assertJsonPath('0.links', 1)->assertJsonPath('1.links', 0);
        $this->api()->getJson("/api/customers/{$a}/pk-location-candidates?q=depo")->assertOk()->assertJsonCount(1);

        // Dala bağla; ikinci kez bağlanamaz; B'nin düğümüne A üzerinden bağlanamaz.
        $this->api()->postJson("/api/customers/{$a}/organizations/{$aBranch}/pk-location-links", ['location_id' => $ofB])->assertCreated();
        $this->api()->postJson("/api/customers/{$a}/organizations/{$aRoot}/pk-location-links", ['location_id' => $ofB])->assertUnprocessable();
        $this->api()->postJson("/api/customers/{$a}/organizations/{$bRoot}/pk-location-links", ['location_id' => $free])->assertUnprocessable();
        $this->api()->getJson("/api/customers/{$a}/pk-location-candidates")->assertOk()->assertJsonCount(1);

        // Ortak lokasyon yalnız A'dan ayrılır, B'de kalır; tek bağlı lokasyon ayrılmaz (önyüz silme akışına geçer).
        $this->api()->deleteJson("/api/customers/{$a}/pk-location-links/{$ofB}")->assertOk()->assertJsonPath('detached', true);
        $this->assertDatabaseHas('organization_locations', ['organization_id' => $bRoot, 'location_id' => $ofB]);
        $this->assertDatabaseMissing('organization_locations', ['organization_id' => $aBranch, 'location_id' => $ofB]);
        $this->api()->deleteJson("/api/customers/{$b}/pk-location-links/{$ofB}")->assertStatus(422)->assertJsonPath('code', 'tek_bag');
    }

    public function test_move_location_to_another_node(): void
    {
        [$a, $aRoot, $aBranch] = $this->customer('A Holding');
        [$b, $bRoot] = $this->customer('B Holding');
        $shared = DB::table('locations')->insertGetId(['tenant_id' => $this->tenantId, 'name' => 'Ortak Kampüs']);
        $onlyB = DB::table('locations')->insertGetId(['tenant_id' => $this->tenantId, 'name' => 'B Depo']);
        DB::table('organization_locations')->insert([['organization_id' => $aRoot, 'location_id' => $shared], ['organization_id' => $bRoot, 'location_id' => $shared], ['organization_id' => $bRoot, 'location_id' => $onlyB]]);

        // Kökten dala taşınır; B'deki bağ kalır.
        $this->api()->patchJson("/api/customers/{$a}/pk-location-links/{$shared}", ['organization_id' => $aBranch])->assertOk()
            ->assertJsonPath('id', $shared)->assertJsonPath('organization_id', $aBranch);
        $this->assertDatabaseMissing('organization_locations', ['organization_id' => $aRoot, 'location_id' => $shared]);
        $this->assertDatabaseHas('organization_locations', ['organization_id' => $aBranch, 'location_id' => $shared]);
        $this->assertDatabaseHas('organization_locations', ['organization_id' => $bRoot, 'location_id' => $shared]);

        // Lokasyon A'ya bağlı değil; hedef düğüm A'nın ağacında değil.
        $this->api()->patchJson("/api/customers/{$a}/pk-location-links/{$onlyB}", ['organization_id' => $aRoot])->assertUnprocessable()->assertJsonValidationErrors('location');
        $this->api()->patchJson("/api/customers/{$a}/pk-location-links/{$shared}", ['organization_id' => $bRoot])->assertUnprocessable()->assertJsonValidationErrors('organization_id');
    }

    public function test_reparent_organization_node(): void
    {
        [$a, $aRoot, $aBranch] = $this->customer('A Holding');
        [$b, $bRoot] = $this->customer('B Holding');
        $second = DB::table('organizations')->insertGetId(['tenant_id' => $this->tenantId, 'parent_id' => $aRoot, 'name' => 'A Dal 2']);
        $leaf = DB::table('organizations')->insertGetId(['tenant_id' => $this->tenantId, 'parent_id' => $aBranch, 'name' => 'A Alt']);

        // Dal, ikinci dalın altına taşınır (altındaki düğümle birlikte).
        $this->api()->patchJson("/api/customers/{$a}/pk-organizations/{$aBranch}/parent", ['parent_id' => $second])->assertOk()
            ->assertJsonPath('id', $aBranch)->assertJsonPath('parent_id', $second);
        $this->assertDatabaseHas('organizations', ['id' => $aBranch, 'parent_id' => $second]);
        $this->assertDatabaseHas('organizations', ['id' => $leaf, 'parent_id' => $aBranch]);

        // Kök taşınmaz; başka ağacın düğümü üst olamaz; kendisi ya da altı üst olamaz.
        $this->api()->patchJson("/api/customers/{$a}/pk-organizations/{$aRoot}/parent", ['parent_id' => $second])->assertUnprocessable()->assertJsonValidationErrors('organization');
        $this->api()->patchJson("/api/customers/{$a}/pk-organizations/{$aBranch}/parent", ['parent_id' => $bRoot])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->api()->patchJson("/api/customers/{$a}/pk-organizations/{$aBranch}/parent", ['parent_id' => $aBranch])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->api()->patchJson("/api/customers/{$a}/pk-organizations/{$aBranch}/parent", ['parent_id' => $leaf])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->assertDatabaseHas('organizations', ['id' => $aBranch, 'parent_id' => $second]);
    }

    public function test_customer_companies_and_sub_facilities(): void
    {
        [$a, $aRoot] = $this->customer('A Holding');
        [$b] = $this->customer('B Holding');
        $location = DB::table('locations')->insertGetId(['tenant_id' => $this->tenantId, 'name' => 'Kampüs']);
        DB::table('organization_locations')->insert(['organization_id' => $aRoot, 'location_id' => $location]);

        // Firma ana firmaya ait; aynı unvan başka ana firmaya eklenemez.
        $firm = $this->api()->postJson("/api/customers/{$a}/firms", ['name' => 'ABC Gıda A.Ş.'])->assertCreated()->json('business_entity_id');
        $this->api()->postJson("/api/customers/{$b}/firms", ['name' => 'abc gıda a.ş.'])->assertUnprocessable();
        $this->api()->getJson("/api/customers/{$a}/firms")->assertOk()->assertJsonCount(1)->assertJsonPath("0.workplaces_count", 0);
        $this->api()->getJson("/api/customers/{$b}/firms")->assertOk()->assertJsonCount(0);

        // Alt tesis: aynı ad iki kez açılmaz; işyeri olan alt tesis silinmez.
        $hangar = $this->api()->postJson("/api/locations/{$location}/sub-facilities", ['name' => 'Hangar 1'])->assertCreated()->json('id');
        $this->api()->postJson("/api/locations/{$location}/sub-facilities", ['name' => 'hangar 1'])->assertUnprocessable();
        DB::table('location_business_entities')->insert(['location_id' => $location, 'business_entity_id' => $firm, 'customer_id' => $a, 'sub_facility_id' => $hangar]);
        $this->api()->deleteJson("/api/locations/{$location}/sub-facilities/{$hangar}")->assertUnprocessable();
        $this->api()->getJson("/api/locations/{$location}/sub-facilities")->assertOk()->assertJsonPath('0.workplaces_count', 1);

        // İşyeri olan firma ana firmadan çıkarılmaz; işyeri olan lokasyonun bağı kaldırılmaz.
        $this->api()->deleteJson("/api/customers/{$a}/firms/{$firm}")->assertUnprocessable();
        // Yalnız A'ya bağlı lokasyon: işyeri olsa da tek_bag döner (önyüz silme akışına geçer).
        $this->api()->deleteJson("/api/customers/{$a}/pk-location-links/{$location}")->assertStatus(422)->assertJsonPath('code', 'tek_bag');
        // Başka lokasyonun alt tesisi bu lokasyondan silinemez.
        $other = DB::table('locations')->insertGetId(['tenant_id' => $this->tenantId, 'name' => 'Depo']);
        $this->api()->deleteJson("/api/locations/{$other}/sub-facilities/{$hangar}")->assertNotFound();
    }

    public function test_additional_workplace_owner_and_sub_facility(): void
    {
        [$a, $aRoot] = $this->customer('A Holding');
        [$b, $bRoot] = $this->customer('B Holding');
        [$c] = $this->customer('C Holding');
        $location = DB::table('locations')->insertGetId(['tenant_id' => $this->tenantId, 'name' => 'Ortak Kampüs']);
        DB::table('organization_locations')->insert([['organization_id' => $aRoot, 'location_id' => $location], ['organization_id' => $bRoot, 'location_id' => $location]]);
        $firm = DB::table('business_entities')->insertGetId(['tenant_id' => $this->tenantId, 'customer_id' => $b, 'type' => 'company', 'name' => 'B Gıda']);
        DB::table('location_business_entities')->insert(['location_id' => $location, 'business_entity_id' => $firm, 'customer_id' => $b]);
        $hangar = DB::table('sub_facilities')->insertGetId(['tenant_id' => $this->tenantId, 'location_id' => $location, 'name' => 'Hangar 1']);
        $elsewhere = DB::table('locations')->insertGetId(['tenant_id' => $this->tenantId, 'name' => 'Başka']);
        $foreignHangar = DB::table('sub_facilities')->insertGetId(['tenant_id' => $this->tenantId, 'location_id' => $elsewhere, 'name' => 'Hangar 2']);
        $body = ['location_id' => $location, 'business_entity_id' => $firm, 'name' => 'Kompresör', 'hazard_class' => 'Tehlikeli'];
        app(\App\Services\PkBillingService::class)->subscription($this->tenantId);
        DB::table('pk_subscriptions')->where('tenant_id', $this->tenantId)->update(['workplace_limit' => null]); // deneme paketinin işyeri limiti bu testin konusu değil

        // Lokasyon C'nin ağacında değil; firma A'ya değil B'ye ait; alt tesis başka lokasyonun.
        $this->api()->postJson('/api/pk-workplaces/additional', $body + ['customer_id' => $c])->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->api()->postJson('/api/pk-workplaces/additional', $body + ['customer_id' => $a])->assertUnprocessable()->assertJsonValidationErrors('business_entity_id');
        $this->api()->postJson('/api/pk-workplaces/additional', $body + ['customer_id' => $b, 'sub_facility_id' => $foreignHangar])->assertUnprocessable()->assertJsonValidationErrors('sub_facility_id');

        $this->api()->postJson('/api/pk-workplaces/additional', $body + ['customer_id' => $b, 'sub_facility_id' => $hangar])->assertCreated()
            ->assertJsonPath('customer_id', $b)->assertJsonPath('sub_facility_id', $hangar)->assertJsonPath('name', 'Kompresör');
        // Ad yine tekil; ana firma verilmezse lokasyonun ilk ana firması (A).
        $this->api()->postJson('/api/pk-workplaces/additional', ['name' => 'kompresör', 'customer_id' => $b] + $body)->assertUnprocessable()->assertJsonValidationErrors('name');
        $free = DB::table('business_entities')->insertGetId(['tenant_id' => $this->tenantId, 'type' => 'company', 'name' => 'Sahipsiz']);
        DB::table('location_business_entities')->insert(['location_id' => $location, 'business_entity_id' => $free]);
        $this->api()->postJson('/api/pk-workplaces/additional', ['business_entity_id' => $free] + $body)->assertCreated()->assertJsonPath('customer_id', $a);
        $this->assertDatabaseHas('business_entities', ['id' => $free, 'customer_id' => $a]);

        // customers/{c}/companies: ortak lokasyonda yalnız kendi (ve sahipsiz) işyerleri.
        $this->api()->getJson("/api/customers/{$a}/companies")->assertOk()->assertJsonPath('customer', [$free]);
        $this->api()->getJson("/api/customers/{$b}/companies")->assertOk()->assertJsonPath('customer', [$firm, $free]);
    }

    public function test_purge_keeps_other_customers_workplaces(): void
    {
        foreach (['pk_equipment', 'pk_inspections', 'periodic_equipment_spec_requests', 'location_business_entity_photos', 'work_requests',
            'operational_regions', 'organization_contractors', 'organization_companies', 'fire_suppression_reports', 'fire_suppression_inventory_items',
            'emergency_equipment_annual_control_reports', 'location_emergency_equipment', 'field_findings'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                foreach (['location_id', 'organization_id', 'location_business_entity_id', 'pk_equipment_id'] as $column) {
                    $t->unsignedBigInteger($column)->nullable();
                }
                $t->string('photo_path')->nullable();
                $t->string('status')->nullable();
            });
        }
        [$a, $aRoot] = $this->customer('A Holding');
        [$b, $bRoot] = $this->customer('B Holding');
        $own = DB::table('locations')->insertGetId(['tenant_id' => $this->tenantId, 'name' => 'A Fabrika']);
        $shared = DB::table('locations')->insertGetId(['tenant_id' => $this->tenantId, 'name' => 'Ortak Kampüs']);
        DB::table('organization_locations')->insert([['organization_id' => $aRoot, 'location_id' => $own], ['organization_id' => $aRoot, 'location_id' => $shared], ['organization_id' => $bRoot, 'location_id' => $shared]]);
        $firm = DB::table('business_entities')->insertGetId(['tenant_id' => $this->tenantId, 'type' => 'company', 'name' => 'Firma']);
        DB::table('location_business_entities')->insert(['location_id' => $own, 'business_entity_id' => $firm, 'customer_id' => $a]);
        $bAtShared = DB::table('location_business_entities')->insertGetId(['location_id' => $shared, 'business_entity_id' => $firm, 'customer_id' => $b]);
        $aAtShared = DB::table('location_business_entities')->insertGetId(['location_id' => $shared, 'business_entity_id' => $firm, 'customer_id' => $a]);
        $bAtOwn = DB::table('location_business_entities')->insertGetId(['location_id' => $own, 'business_entity_id' => $firm, 'customer_id' => $b]);

        // B'nin A'nın lokasyonundaki işyeri ve A'nın ortak lokasyondaki işyeri silmeyi durdurur.
        $blockers = $this->api()->getJson("/api/customers/{$a}/purge-summary")->assertOk()->assertJsonPath('locations', 1)->json('blockers');
        $this->assertSame(['1 işyeri başka bir ana firmaya ait', '1 işyeri başka ana firmayla ortak lokasyonda; önce bu işyerlerini kaldırın'], $blockers);
        $this->api()->deleteJson("/api/customers/{$a}/purge")->assertUnprocessable();

        DB::table('location_business_entities')->whereIn('id', [$aAtShared, $bAtOwn])->delete();
        $this->api()->getJson("/api/customers/{$a}/purge-summary")->assertOk()->assertJsonPath('blockers', [])->assertJsonPath('workplaces', 1);
        $this->api()->deleteJson("/api/customers/{$a}/purge")->assertOk();
        $this->assertDatabaseMissing('locations', ['id' => $own]);
        $this->assertDatabaseHas('locations', ['id' => $shared]);
        $this->assertDatabaseHas('location_business_entities', ['id' => $bAtShared]);
        $this->assertDatabaseHas('organization_locations', ['organization_id' => $bRoot, 'location_id' => $shared]);
    }
}
