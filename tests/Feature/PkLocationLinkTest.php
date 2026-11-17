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
        Schema::create('organization_locations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('location_id');
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
}
