<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SuperAdminExpertOnboardingService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Kontrol planlama uçları (pk-control-plans). Tablolar PkNotificationTest'teki gibi kurulur.
class PkControlPlanTest extends TestCase
{
    private int $tenantId;
    private User $user;
    private int $locationId;
    private int $otherLocationId;
    private int $typeId;
    private int $tenant2;
    private User $user2;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlite.foreign_key_constraints' => false]);
        DB::purge('sqlite');
        $migrations = [...PkNotificationTest::MIGRATIONS, '2026_11_15_110000_create_pk_control_plans_table.php'];
        Artisan::call('migrate', ['--path' => array_map(fn ($f) => 'database/migrations/' . $f, $migrations)]);
        Role::findOrCreate('tenant', 'web');
        Role::findOrCreate('isg', 'web');
        PkNotificationTest::tables();
        Mail::fake();

        $result = app(SuperAdminExpertOnboardingService::class)->create('Uzman', 'Uzman Kişi', 'uzman@example.test');
        $this->tenantId = (int) $result['tenant']->id;
        $this->user = User::query()->findOrFail($result['user']['id']);
        $this->locationId = DB::table('locations')->insertGetId(['tenant_id' => $this->tenantId, 'name' => 'Merkez']);
        $this->otherLocationId = DB::table('locations')->insertGetId(['tenant_id' => $this->tenantId, 'name' => 'Depo']);
        $this->typeId = DB::table('periodic_equipment_types')->insertGetId(['name' => 'Yangın Tüpü']);

        $other = app(SuperAdminExpertOnboardingService::class)->create('Diğer', 'Diğer Uzman', 'diger@example.test');
        $this->tenant2 = (int) $other['tenant']->id;
        $this->user2 = User::query()->findOrFail($other['user']['id']);
    }

    private function api(?User $user = null, ?int $tenantId = null): static
    {
        return $this->actingAs($user ?? $this->user, 'sanctum')->withHeader('X-Tenant-ID', (string) ($tenantId ?? $this->tenantId));
    }

    public function test_plan_ekle_listele_guncelle_sil(): void
    {
        $businessId = DB::table('business_entities')->insertGetId(['tenant_id' => $this->tenantId, 'name' => 'Firma A Kısa']);
        DB::table('companies')->insert(['business_entity_id' => $businessId, 'name' => 'Firma A']);
        $workplace = DB::table('location_business_entities')->insertGetId(['location_id' => $this->locationId, 'business_entity_id' => $businessId]);
        $tube = DB::table('pk_equipment')->insertGetId(['tenant_id' => $this->tenantId, 'location_id' => $this->locationId, 'equipment_type_id' => $this->typeId, 'code' => 'YT-01']);
        $installation = DB::table('pk_installations')->insertGetId(['tenant_id' => $this->tenantId, 'location_id' => $this->locationId, 'name' => 'Yangın Tesisatı']);

        $plan = $this->api()->postJson('/api/pk-control-plans', [
            'date' => '2026-12-01', 'location_id' => $this->locationId, 'workplace_id' => $workplace, 'equipment_type_id' => $this->typeId,
            'equipment_ids' => [$tube], 'installation_id' => $installation, 'title' => 'Tüp kontrolü', 'note' => 'Sabah',
        ])->assertCreated()
            ->assertJsonPath('location_name', 'Merkez')->assertJsonPath('workplace_name', 'Firma A')->assertJsonPath('equipment_type_name', 'Yangın Tüpü')
            ->assertJsonPath('equipment.0.code', 'YT-01')->assertJsonPath('equipment.0.name', 'Yangın Tüpü')
            ->assertJsonPath('installation.name', 'Yangın Tesisatı')->assertJsonPath('created_by_name', 'Uzman Kişi')->json();

        $this->api()->getJson("/api/pk-control-plans?location_ids[]={$this->locationId}&from=2026-12-01&to=2026-12-31")->assertOk()
            ->assertJsonCount(1)->assertJsonPath('0.id', $plan['id'])->assertJsonPath('0.date', '2026-12-01');
        $this->api()->getJson("/api/pk-control-plans?location_ids[]={$this->otherLocationId}")->assertOk()->assertJsonCount(0);
        $this->api()->getJson('/api/pk-control-plans?from=2027-01-01')->assertOk()->assertJsonCount(0);

        $this->api()->patchJson("/api/pk-control-plans/{$plan['id']}", ['date' => '2026-12-05', 'equipment_ids' => []])->assertOk()
            ->assertJsonPath('date', '2026-12-05')->assertJsonPath('equipment_ids', [])->assertJsonPath('installation.name', 'Yangın Tesisatı');
        // Lokasyon değişince tesisat yeni lokasyonun olmalı.
        $this->api()->patchJson("/api/pk-control-plans/{$plan['id']}", ['location_id' => $this->otherLocationId])->assertStatus(422)->assertJsonValidationErrors(['installation_id', 'workplace_id']);

        $this->api()->deleteJson("/api/pk-control-plans/{$plan['id']}")->assertNoContent();
        $this->api()->getJson('/api/pk-control-plans')->assertOk()->assertJsonCount(0);
        $this->assertNotNull(DB::table('pk_control_plans')->where('id', $plan['id'])->value('deleted_at'));
    }

    public function test_kapsam_disi_lokasyon_ekipman_ve_baska_hesap(): void
    {
        $foreignLocation = DB::table('locations')->insertGetId(['tenant_id' => $this->tenant2, 'name' => 'Başka hesap']);
        $elsewhere = DB::table('pk_equipment')->insertGetId(['tenant_id' => $this->tenantId, 'location_id' => $this->otherLocationId, 'equipment_type_id' => $this->typeId]);
        $foreignWorkplace = DB::table('location_business_entities')->insertGetId(['location_id' => $foreignLocation]);

        $this->api()->postJson('/api/pk-control-plans', ['location_id' => $this->locationId])->assertStatus(422)->assertJsonValidationErrors('date');
        $this->api()->postJson('/api/pk-control-plans', ['date' => '2026-12-01', 'location_id' => $foreignLocation])->assertStatus(422)->assertJsonValidationErrors('location_id');
        $this->api()->postJson('/api/pk-control-plans', ['date' => '2026-12-01', 'location_id' => $this->locationId, 'equipment_ids' => [$elsewhere], 'workplace_id' => $foreignWorkplace])
            ->assertStatus(422)->assertJsonValidationErrors(['equipment_ids', 'workplace_id']);

        $plan = $this->api()->postJson('/api/pk-control-plans', ['date' => '2026-12-01', 'location_id' => $this->locationId])->assertCreated()->json();
        // Başka hesabın kullanıcısı kendi hesabıyla planı bulamaz, bu hesabın başlığıyla 403 alır.
        $this->api($this->user2, $this->tenant2)->getJson('/api/pk-control-plans')->assertOk()->assertJsonCount(0);
        $this->api($this->user2, $this->tenant2)->patchJson("/api/pk-control-plans/{$plan['id']}", ['date' => '2026-12-09'])->assertNotFound();
        $this->api($this->user2, $this->tenant2)->deleteJson("/api/pk-control-plans/{$plan['id']}")->assertNotFound();
        $this->api($this->user2, $this->tenantId)->getJson('/api/pk-control-plans')->assertForbidden();
        // Hesap başlığı yok: tenant bağlamı kurulmaz (testte bağlam istekler arasında kaldığı için temizlenir).
        app(\App\Domain\Tenancy\TenantContext::class)->clear();
        $this->flushHeaders()->actingAs($this->user, 'sanctum')->getJson('/api/pk-control-plans')->assertForbidden();
        $this->assertStringStartsWith('2026-12-01', (string) DB::table('pk_control_plans')->where('id', $plan['id'])->value('date'));
    }
}
