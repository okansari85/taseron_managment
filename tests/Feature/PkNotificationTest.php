<?php

namespace Tests\Feature;

use App\Domain\Tenancy\TenantContext;
use App\Models\PkAccountUser;
use App\Models\User;
use App\Repositories\UserScopeRepository;
use App\Services\PkAccountService;
use App\Services\PkInstallationService;
use App\Services\SuperAdminExpertOnboardingService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Panel bildirimleri (PkNotificationService, pk:notifications-generate, pk-notifications uçları). Bellekteki sqlite; ekipman
 * tabloları yalnız gereken kolonlarla kurulur, tesisat listesi (PkInstallationService) taklit edilir.
 */
class PkNotificationTest extends TestCase
{
    public const MIGRATIONS = [
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
        '2026_11_12_120000_create_pk_findings_tables.php',
        '2026_11_15_100000_create_pk_notifications_table.php',
    ];

    private int $tenantId;
    private User $user;
    private int $locationId;
    private int $typeId;
    private int $otherTenantId;
    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlite.foreign_key_constraints' => false]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--path' => array_map(fn ($f) => 'database/migrations/' . $f, self::MIGRATIONS)]);
        Role::findOrCreate('tenant', 'web');
        Role::findOrCreate('isg', 'web');
        self::tables();
        Mail::fake();
        Carbon::setTestNow('2026-11-15 08:00:00');

        $result = app(SuperAdminExpertOnboardingService::class)->create('Uzman', 'Uzman Kişi', 'uzman@example.test');
        $this->tenantId = (int) $result['tenant']->id;
        $this->user = User::query()->findOrFail($result['user']['id']);
        $this->locationId = DB::table('locations')->insertGetId(['tenant_id' => $this->tenantId, 'name' => 'Merkez']);
        $this->typeId = DB::table('periodic_equipment_types')->insertGetId(['name' => 'Yangın Tüpü']);

        $other = app(SuperAdminExpertOnboardingService::class)->create('Diğer', 'Diğer Uzman', 'diger@example.test');
        $this->otherTenantId = (int) $other['tenant']->id;
        $this->otherUser = User::query()->findOrFail($other['user']['id']);

        // Tesisat listesi: yalnız ilk hesapta bugün dolan bir tesisat.
        $this->mock(PkInstallationService::class, fn ($mock) => $mock->shouldReceive('list')->andReturnUsing(fn () => app(TenantContext::class)->id() === $this->tenantId
            ? collect([['id' => 7, 'name' => 'Yangın Tesisatı', 'location' => ['id' => $this->locationId, 'name' => 'Merkez'], 'next_control_date' => '2026-11-15', 'days_left' => 0]])
            : collect()));
    }

    public static function tables(): void
    {
        Schema::create('locations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id');
            $t->string('name')->nullable();
            $t->boolean('is_active')->default(true);
        });
        Schema::create('location_business_entities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('location_id');
            $t->unsignedBigInteger('business_entity_id')->nullable();
            $t->boolean('is_active')->default(true);
        });
        Schema::create('business_entities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id');
            $t->string('name')->nullable();
        });
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_entity_id');
            $t->string('name')->nullable();
        });
        Schema::create('location_experts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('location_business_entity_id');
            $t->foreignId('user_id');
            $t->timestamps();
        });
        Schema::create('periodic_equipment_types', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });
        Schema::create('pk_equipment', function (Blueprint $t) {
            $t->id();
            foreach (['tenant_id', 'location_id', 'location_business_entity_id', 'equipment_type_id'] as $column) {
                $t->unsignedBigInteger($column)->nullable();
            }
            $t->string('name')->nullable();
            $t->string('code')->nullable();
            $t->date('next_control_date')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('pk_installations', function (Blueprint $t) {
            $t->id();
            foreach (['tenant_id', 'location_id', 'location_business_entity_id', 'installation_type_id'] as $column) {
                $t->unsignedBigInteger($column)->nullable();
            }
            $t->string('name')->nullable();
            $t->timestamps();
        });
    }

    private function equipment(int $tenantId, int $locationId, string $code, ?string $next, bool $active = true): int
    {
        return DB::table('pk_equipment')->insertGetId(['tenant_id' => $tenantId, 'location_id' => $locationId, 'equipment_type_id' => $this->typeId, 'code' => $code, 'next_control_date' => $next, 'is_active' => $active]);
    }

    private function notifications(User $user): \Illuminate\Support\Collection
    {
        return DB::table('pk_notifications')->where('user_id', $user->id)->orderBy('id')->get();
    }

    public function test_kontrol_ve_bulgu_bildirimleri_uretilir_ikinci_kez_uretilmez(): void
    {
        $a = $this->equipment($this->tenantId, $this->locationId, 'YT-01', '2026-11-22');
        $b = $this->equipment($this->tenantId, $this->locationId, 'YT-02', '2026-11-22');
        $late = $this->equipment($this->tenantId, $this->locationId, 'YT-03', '2026-11-10');
        $this->equipment($this->tenantId, $this->locationId, 'YT-04', '2027-01-30');
        $this->equipment($this->tenantId, $this->locationId, 'YT-05', '2026-11-01', active: false);
        $findingId = DB::table('pk_findings')->insertGetId(['tenant_id' => $this->tenantId, 'location_id' => $this->locationId, 'text' => 'Etiket yok', 'text_key' => sha1('etiket yok'),
            'status' => 'acik', 'responsible_user_id' => $this->user->id, 'due_date' => '2026-11-18']);
        DB::table('pk_findings')->insert(['tenant_id' => $this->tenantId, 'location_id' => $this->locationId, 'text' => 'Kapanan', 'text_key' => sha1('kapanan'),
            'status' => 'kapandi', 'responsible_user_id' => $this->user->id, 'due_date' => '2026-11-16']);
        // Başka hesabın ekipmanı yalnız o hesabın kullanıcısına.
        $otherLocation = DB::table('locations')->insertGetId(['tenant_id' => $this->otherTenantId, 'name' => 'Şube']);
        $this->equipment($this->otherTenantId, $otherLocation, 'B-01', '2026-11-01');

        $this->artisan('pk:notifications-generate')->assertSuccessful();
        $this->artisan('pk:notifications-generate')->assertSuccessful();

        $rows = $this->notifications($this->user)->keyBy('link');
        $this->assertCount(4, $rows);
        $group = $rows['/expert/kontroller/7-gun'];
        $this->assertSame('kontrol_yaklasan', $group->type);
        $this->assertSame('Merkez · 2 ekipman: kontrol tarihine 7 gün kaldı (22.11.2026).', $group->body);
        $this->assertSame([$a, $b], json_decode($group->payload, true)['equipment_ids']);
        $this->assertSame('kontrol_geciken', $rows["/expert/equipment/{$late}"]->type);
        $this->assertSame('Merkez · Yangın Tüpü YT-03: kontrol tarihi 10.11.2026 idi.', $rows["/expert/equipment/{$late}"]->body);
        $this->assertSame('Kontrol tarihi bugün', $rows['/expert/installations']->title);
        $finding = $rows["/expert/bulgu-takip?bulgu={$findingId}"];
        $this->assertSame(['bulgu_hatirlatma', 'Bulgunun terminine 3 gün kaldı'], [$finding->type, $finding->title]);
        $this->assertCount(1, $this->notifications($this->otherUser));
        $this->assertSame((int) $this->otherTenantId, (int) $this->notifications($this->otherUser)->first()->tenant_id);

        // Bir hafta sonra: grup bugün dolar (yeni aralık), geciken ve tesisat aynı (tekrar yok), bulgunun termini geçti.
        Carbon::setTestNow('2026-11-22 08:00:00');
        $this->artisan('pk:notifications-generate')->assertSuccessful();
        $new = $this->notifications($this->user)->slice(4)->values();
        $this->assertSame(['/expert/kontroller/bugun', "/expert/bulgu-takip?bulgu={$findingId}"], $new->pluck('link')->all());
        $this->assertSame(['Kontrol tarihi bugün', 'Bulgunun termini geçti'], $new->pluck('title')->all());
    }

    public function test_osgb_hesabinda_uzman_yalniz_atandigi_lokasyonu_gorur_pasif_almaz(): void
    {
        $account = app(PkAccountService::class)->create('osgb', 'OSGB', 'Yönetici', 'yonetici@example.test');
        $tenantId = (int) $account['tenant']->id;
        $manager = User::query()->findOrFail($account['user']['id']);
        $member = function (string $name, string $role, bool $passive = false) use ($tenantId) {
            $user = User::query()->create(['name' => $name, 'email' => "{$role}-" . md5($name) . '@example.test', 'password' => 'x', 'is_expert' => true]);
            $user->assignRole($role === 'yonetici' ? 'tenant' : 'isg');
            app(UserScopeRepository::class)->attach($user, 'tenant', $tenantId);
            PkAccountUser::query()->create(['tenant_id' => $tenantId, 'user_id' => $user->id, 'role' => $role, 'deactivated_at' => $passive ? now() : null]);

            return $user;
        };
        $operation = $member('Operasyon', 'operasyon');
        $assigned = $member('Atanan Uzman', 'uzman');
        $free = $member('Atanmayan Uzman', 'uzman');
        $passive = $member('Pasif Uzman', 'uzman', passive: true);

        $first = DB::table('locations')->insertGetId(['tenant_id' => $tenantId, 'name' => 'Birinci']);
        $second = DB::table('locations')->insertGetId(['tenant_id' => $tenantId, 'name' => 'İkinci']);
        $workplace = DB::table('location_business_entities')->insertGetId(['location_id' => $first]);
        DB::table('location_experts')->insert([['location_business_entity_id' => $workplace, 'user_id' => $assigned->id], ['location_business_entity_id' => $workplace, 'user_id' => $passive->id]]);
        $this->equipment($tenantId, $first, 'A-01', '2026-11-01');
        $this->equipment($tenantId, $second, 'B-01', '2026-11-01');

        $this->artisan('pk:notifications-generate')->assertSuccessful();

        $this->assertSame(2, $this->notifications($manager)->count());
        $this->assertSame(2, $this->notifications($operation)->count());
        $this->assertSame(['Birinci · Yangın Tüpü A-01: kontrol tarihi 01.11.2026 idi.'], $this->notifications($assigned)->pluck('body')->all());
        $this->assertSame(0, $this->notifications($free)->count());
        $this->assertSame(0, $this->notifications($passive)->count());
    }

    public function test_liste_okundu_ve_hepsini_okundu_yalniz_kendi_bildirimleri(): void
    {
        $row = fn (User $user, int $tenantId, string $key) => ['tenant_id' => $tenantId, 'user_id' => $user->id, 'type' => 'kontrol_geciken', 'title' => 'Kontrol süresi geçti',
            'body' => 'Merkez', 'link' => '/expert/kontroller/suresi-gecen', 'dedupe_key' => $key, 'created_at' => now(), 'updated_at' => now()];
        DB::table('pk_notifications')->insert([$row($this->user, $this->tenantId, 'a'), $row($this->user, $this->tenantId, 'b'), $row($this->user, $this->tenantId, 'c')]);
        $foreign = DB::table('pk_notifications')->insertGetId($row($this->otherUser, $this->otherTenantId, 'a'));
        $api = fn () => $this->actingAs($this->user, 'sanctum')->withHeader('X-Tenant-ID', (string) $this->tenantId);

        $list = $api()->getJson('/api/pk-notifications?per_page=2')->assertOk()
            ->assertJsonPath('total', 3)->assertJsonPath('unread_count', 3)->assertJsonPath('last_page', 2)->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.link', '/expert/kontroller/suresi-gecen')->assertJsonPath('data.0.read_at', null)->json();
        $id = $list['data'][0]['id'];

        $api()->postJson("/api/pk-notifications/{$id}/read")->assertOk()->assertJsonPath('id', $id)->assertJsonPath('unread_count', 2);
        $this->assertNotNull(DB::table('pk_notifications')->where('id', $id)->value('read_at'));
        $api()->postJson("/api/pk-notifications/{$foreign}/read")->assertNotFound();
        $api()->getJson('/api/pk-notifications?unread=1')->assertOk()->assertJsonPath('total', 2);

        $api()->postJson('/api/pk-notifications/read-all')->assertOk()->assertJsonPath('updated', 2)->assertJsonPath('unread_count', 0);
        $api()->getJson('/api/pk-notifications?unread=1')->assertOk()->assertJsonPath('total', 0);
        $this->assertNull(DB::table('pk_notifications')->where('id', $foreign)->value('read_at'));
    }
}
