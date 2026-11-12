<?php

namespace Tests\Feature;

use App\Mail\PkFindingReminderMail;
use App\Models\PkFinding;
use App\Models\PkInspection;
use App\Models\PkInstallationReport;
use App\Models\PkInstallationReportComponent;
use App\Models\PkInstallationReportSystem;
use App\Models\User;
use App\Services\SuperAdminExpertOnboardingService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Bulgu Takip (PkFindingSyncService, PkFindingService, gözlemci, komutlar). Bellekteki sqlite; ekipman / tesisat tabloları
 * yalnız gereken kolonlarla kurulur (asıl migration'lar sqlite'ta çalışmıyor).
 */
class PkFindingTest extends TestCase
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
        '2026_11_12_120000_create_pk_findings_tables.php',
    ];

    private int $tenantId;
    private User $user;
    private int $equipmentId;
    private int $installationId;
    private int $systemId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlite.foreign_key_constraints' => false]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--path' => array_map(fn ($f) => 'database/migrations/' . $f, self::MIGRATIONS)]);
        Role::findOrCreate('tenant', 'web');
        Role::findOrCreate('isg', 'web');
        $this->tables();
        Mail::fake();
        Storage::fake('local');

        $result = app(SuperAdminExpertOnboardingService::class)->create('Uzman', 'Uzman Kişi', 'uzman@example.test');
        $this->tenantId = (int) $result['tenant']->id;
        $this->user = User::query()->findOrFail($result['user']['id']);

        $locationId = DB::table('locations')->insertGetId(['tenant_id' => $this->tenantId, 'name' => 'Merkez', 'is_active' => true]);
        $typeId = DB::table('periodic_equipment_types')->insertGetId(['name' => 'Yangın Tüpü']);
        $this->equipmentId = DB::table('pk_equipment')->insertGetId(['tenant_id' => $this->tenantId, 'location_id' => $locationId, 'equipment_type_id' => $typeId, 'code' => 'YT-01', 'is_active' => true]);
        $this->installationId = DB::table('pk_installations')->insertGetId(['tenant_id' => $this->tenantId, 'location_id' => $locationId, 'installation_type_id' => $typeId, 'name' => 'Yangın Tesisatı']);
        $this->systemId = DB::table('pk_installation_systems')->insertGetId(['tenant_id' => $this->tenantId, 'pk_installation_id' => $this->installationId, 'name' => 'Yangın Dolapları']);
    }

    private function tables(): void
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
            $t->boolean('is_active')->default(true);
        });
        Schema::create('periodic_equipment_types', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });
        Schema::create('periodic_installation_systems', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });
        Schema::create('pk_equipment', function (Blueprint $t) {
            $t->id();
            foreach (['tenant_id', 'location_id', 'location_business_entity_id', 'equipment_type_id', 'last_inspection_id'] as $column) {
                $t->unsignedBigInteger($column)->nullable();
            }
            foreach (['name', 'code', 'last_status'] as $column) {
                $t->string($column)->nullable();
            }
            $t->date('last_control_date')->nullable();
            $t->date('next_control_date')->nullable();
            $t->integer('last_findings_count')->nullable();
            $t->json('last_findings')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('pk_inspections', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('pk_equipment_id');
            $t->date('control_date')->nullable();
            $t->date('next_control_date')->nullable();
            foreach (['status', 'source', 'report_file', 'report_file_name', 'report_no', 'report_hash', 'note', 'inspection_body', 'conclusion'] as $column) {
                $t->string($column)->nullable();
            }
            $t->json('findings')->nullable();
            $t->json('analysis')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('pk_installations', function (Blueprint $t) {
            $t->id();
            foreach (['tenant_id', 'location_id', 'location_business_entity_id', 'installation_type_id', 'created_by'] as $column) {
                $t->unsignedBigInteger($column)->nullable();
            }
            $t->string('name')->nullable();
            $t->string('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('pk_installation_systems', function (Blueprint $t) {
            $t->id();
            foreach (['tenant_id', 'pk_installation_id', 'system_id'] as $column) {
                $t->unsignedBigInteger($column)->nullable();
            }
            $t->string('name')->nullable();
            $t->integer('sort_order')->default(0);
            $t->timestamps();
        });
        Schema::create('pk_installation_reports', function (Blueprint $t) {
            $t->id();
            foreach (['tenant_id', 'pk_installation_id', 'created_by'] as $column) {
                $t->unsignedBigInteger($column)->nullable();
            }
            $t->date('control_date')->nullable();
            $t->date('next_control_date')->nullable();
            foreach (['status', 'source', 'report_scope', 'report_file', 'report_file_name', 'report_no', 'report_hash', 'inspection_body', 'conclusion', 'notes'] as $column) {
                $t->string($column)->nullable();
            }
            $t->json('findings')->nullable();
            $t->json('analysis')->nullable();
            $t->timestamps();
        });
        Schema::create('pk_installation_report_systems', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('pk_installation_report_id');
            $t->unsignedBigInteger('pk_installation_system_id');
            $t->string('status')->nullable();
            $t->json('source_names')->nullable();
            $t->json('findings')->nullable();
            $t->json('equipment_summary')->nullable();
            $t->timestamps();
        });
        Schema::create('pk_installation_report_components', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('pk_installation_report_system_id');
            $t->json('components')->nullable();
            $t->timestamps();
        });
        Schema::create('pk_installation_report_inspections', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('pk_installation_report_id');
            $t->unsignedBigInteger('pk_inspection_id');
            $t->timestamps();
        });
    }

    private function inspection(string $date, string $status, array $findings): PkInspection
    {
        return PkInspection::query()->create([
            'tenant_id' => $this->tenantId, 'pk_equipment_id' => $this->equipmentId, 'control_date' => $date, 'status' => $status,
            'source' => 'manual', 'findings' => $findings ?: null,
        ]);
    }

    private function api(): static
    {
        return $this->actingAs($this->user, 'sanctum')->withHeader('X-Tenant-ID', (string) $this->tenantId);
    }

    private function findings(): \Illuminate\Support\Collection
    {
        return PkFinding::withoutGlobalScopes()->orderBy('id')->get();
    }

    public function test_ekipman_raporunun_bulgulari_acilir_tekrar_eden_baglanir_uygun_rapor_kapatma_onerir(): void
    {
        $first = $this->inspection('2026-10-01', 'uygun_degil', ['Etiket   yok', 'Basınç düşük', 'Etiket yok']);
        $this->assertSame(['Etiket yok', 'Basınç düşük'], $this->findings()->pluck('text')->all());
        $this->assertSame(['acik', 'acik'], $this->findings()->pluck('status')->all());

        // Yeni rapor uygun değil, bir bulgu tekrar ediyor: yeni kayıt açılmaz, rapor ona bağlanır.
        $this->inspection('2026-10-20', 'uygun_degil', ['etiket yok', 'Pim eksik']);
        $this->assertSame(3, $this->findings()->count());
        $label = $this->findings()->first();
        $this->assertSame('2026-10-20', $label->last_seen_date->format('Y-m-d'));
        $this->assertSame(2, DB::table('pk_finding_sources')->where('pk_finding_id', $label->id)->count());
        $this->assertNull($this->findings()->firstWhere('close_suggested_by', '!=', null));

        // Yeni uygun rapor: bütün açık bulgulara kapatma önerisi; kapanmaz.
        $last = $this->inspection('2026-11-05', 'uygun', []);
        $this->assertSame(['acik', 'acik', 'acik'], $this->findings()->pluck('status')->all());
        $this->assertSame(["inspection:{$last->id}"], $this->findings()->pluck('close_suggested_by')->unique()->values()->all());
        $this->assertSame(3, DB::table('pk_finding_events')->where('type', 'oneri')->count());
        // Aynı kayıt yeniden kaydedilince öneri ikinci kez düşmez.
        $last->update(['note' => 'not']);
        $this->assertSame(3, DB::table('pk_finding_events')->where('type', 'oneri')->count());

        // Kullanıcı biri için öneriyi onaylar (kapatır), birini reddeder.
        [$a, $b] = $this->findings()->take(2)->all();
        $this->api()->postJson("/api/pk-findings/{$a->id}/close")->assertOk()->assertJsonPath('status', 'kapandi')->assertJsonPath('close_suggestion', null);
        $this->api()->postJson("/api/pk-findings/{$b->id}/reject-suggestion")->assertOk()->assertJsonPath('status', 'acik')->assertJsonPath('close_suggestion', null);
        $this->api()->getJson('/api/pk-findings')->assertOk()
            ->assertJsonPath('counts.acik', 2)->assertJsonPath('counts.kapandi', 1)->assertJsonPath('total', 3)
            ->assertJsonPath('data.0.subject.name', 'Yangın Tüpü')->assertJsonPath('data.0.subject.code', 'YT-01');
        $this->api()->getJson('/api/pk-findings/count')->assertOk()->assertJsonPath('open', 2);
        $this->assertNotNull($first);
    }

    public function test_tesisat_raporu_genel_sistem_ve_bilesen_bulgulari_ekipmana_yazilan_kayit_atlanir(): void
    {
        DB::transaction(function () {
            $report = PkInstallationReport::query()->create([
                'tenant_id' => $this->tenantId, 'pk_installation_id' => $this->installationId, 'control_date' => '2026-10-10', 'status' => 'uygun_degil',
                'findings' => ['Genel: yönlendirme levhası yok', 'Dolap camı kırık', 'Hortum çatlak'],
            ]);
            $result = PkInstallationReportSystem::query()->create([
                'pk_installation_report_id' => $report->id, 'pk_installation_system_id' => $this->systemId, 'status' => 'uygun_degil',
                'findings' => ['Dolap camı kırık', 'Hortum çatlak'],
            ]);
            PkInstallationReportComponent::query()->create(['pk_installation_report_system_id' => $result->id, 'components' => [
                ['code' => 'YD-3', 'equipment_name' => 'Yangın dolabı', 'findings' => ['Hortum çatlak']],
            ]]);
            // Tesisat raporundan ekipmana yazılan kontrol kaydı: bulguları raporda var, ayrıca açılmaz.
            $inspection = $this->inspection('2026-10-10', 'uygun_degil', ['Hortum çatlak']);
            DB::table('pk_installation_report_inspections')->insert(['pk_installation_report_id' => $report->id, 'pk_inspection_id' => $inspection->id]);
        });

        $rows = $this->findings()->map(fn (PkFinding $f) => [$f->text, $f->pk_installation_system_id ? 's' : 'g', $f->component, $f->pk_equipment_id])->all();
        $this->assertEqualsCanonicalizing([
            ['Genel: yönlendirme levhası yok', 'g', null, null],
            ['Dolap camı kırık', 's', null, null],
            ['Hortum çatlak', 's', 'Yangın dolabı YD-3', null],
        ], $rows);
        $this->api()->getJson('/api/pk-findings?status=acik')->assertOk()->assertJsonPath('total', 3)
            ->assertJsonPath('data.0.subject.kind', 'installation');
    }

    public function test_rapor_silinince_islem_gormeyen_bulgu_silinir_islem_goren_kalir(): void
    {
        $inspection = $this->inspection('2026-10-01', 'uygun_degil', ['Etiket yok', 'Basınç düşük']);
        $touched = $this->findings()->first();
        $this->api()->patchJson("/api/pk-findings/{$touched->id}", ['responsible_user_id' => $this->user->id, 'due_date' => '2026-12-01', 'work' => 'Etiket yenilenecek'])
            ->assertOk()->assertJsonPath('responsible.name', 'Uzman Kişi')->assertJsonPath('work', 'Etiket yenilenecek');

        // Düzenleme: çıkan bulgu (işlemsiz) silinir, yeni metin eklenir.
        $inspection->update(['findings' => ['Etiket yok', 'Pim eksik']]);
        $this->assertSame(['Etiket yok', 'Pim eksik'], $this->findings()->pluck('text')->all());

        $inspection->delete();
        $this->assertSame(['Etiket yok'], $this->findings()->pluck('text')->all());
        $this->assertTrue(DB::table('pk_finding_events')->where('pk_finding_id', $touched->id)->where('type', 'rapordan_cikarildi')->exists());
    }

    public function test_sorumlu_hesap_kullanicisi_olmali_not_dosya_ve_yetki(): void
    {
        $this->inspection('2026-10-01', 'uygun_degil', ['Etiket yok']);
        $finding = $this->findings()->first();
        $stranger = User::query()->create(['name' => 'Başkası', 'email' => 'baska@example.test', 'password' => 'x', 'is_expert' => true]);

        $this->api()->getJson('/api/pk-findings/responsibles')->assertOk()->assertJsonPath('0.name', 'Uzman Kişi');
        $this->api()->patchJson("/api/pk-findings/{$finding->id}", ['responsible_user_id' => $stranger->id])->assertStatus(422)->assertJsonValidationErrors('responsible_user_id');
        $this->api()->patchJson("/api/pk-findings/{$finding->id}", ['status' => 'kapandi'])->assertStatus(422);
        $this->api()->patchJson("/api/pk-findings/{$finding->id}", ['recheck_date' => '2026-12-10', 'status' => 'tekrar_kontrol'])->assertOk()->assertJsonPath('status', 'tekrar_kontrol');
        $this->api()->getJson('/api/pk-findings/rechecks?from=2026-12-01&to=2026-12-31')->assertOk()->assertJsonPath('0.recheck_date', '2026-12-10');

        $this->api()->postJson("/api/pk-findings/{$finding->id}/notes", ['note' => 'Firmaya bildirildi'])->assertCreated();
        $show = $this->api()->post("/api/pk-findings/{$finding->id}/files", ['file' => UploadedFile::fake()->create('foto.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()->json();
        $file = collect($show['events'])->firstWhere('type', 'dosya');
        $this->assertSame('foto.pdf', $file['file_name']);
        $this->api()->get("/api/pk-findings/{$finding->id}/files/{$file['id']}")->assertOk();

        // Başka hesabın kullanıcısı bu bulguyu göremez.
        $other = app(SuperAdminExpertOnboardingService::class)->create('Diğer', 'Diğer Uzman', 'diger@example.test');
        $this->actingAs(User::query()->findOrFail($other['user']['id']), 'sanctum')->withHeader('X-Tenant-ID', (string) $other['tenant']->id)
            ->getJson("/api/pk-findings/{$finding->id}")->assertNotFound();
    }

    public function test_hatirlatma_10_ve_3_gun_kala_ve_termin_gecince_birer_kez(): void
    {
        Carbon::setTestNow('2026-11-12 08:00:00');
        $this->inspection('2026-10-01', 'uygun_degil', ['Etiket yok']);
        $finding = $this->findings()->first();
        $finding->update(['responsible_user_id' => $this->user->id, 'due_date' => '2026-11-22']);

        $this->artisan('pk:finding-reminders')->assertSuccessful();
        $this->artisan('pk:finding-reminders')->assertSuccessful();
        Mail::assertSent(PkFindingReminderMail::class, 1);

        Carbon::setTestNow('2026-11-20 08:00:00');
        $this->artisan('pk:finding-reminders')->assertSuccessful();
        Carbon::setTestNow('2026-11-23 08:00:00');
        $this->artisan('pk:finding-reminders')->assertSuccessful();
        $this->artisan('pk:finding-reminders')->assertSuccessful();
        Mail::assertSent(PkFindingReminderMail::class, 3);
        $this->assertSame(['10', '3', 'gecti'], DB::table('pk_finding_events')->where('type', 'hatirlatma')->orderBy('id')->pluck('meta')
            ->map(fn ($meta) => json_decode($meta, true)['kind'])->all());

        // Kapanan bulguya hatırlatma gitmez.
        $finding->update(['status' => 'kapandi', 'due_date' => '2026-11-25']);
        $this->artisan('pk:finding-reminders')->assertSuccessful();
        Mail::assertSent(PkFindingReminderMail::class, 3);
    }

    public function test_aktarma_yalniz_en_son_raporlari_alir_ve_tekrar_calistirmak_guvenli(): void
    {
        // Gözlemciyi atlayan eski kayıtlar (Bulgu Takip'ten önce).
        $old = DB::table('pk_inspections')->insertGetId(['tenant_id' => $this->tenantId, 'pk_equipment_id' => $this->equipmentId, 'control_date' => '2026-01-01', 'status' => 'uygun_degil', 'findings' => json_encode(['Eski bulgu'])]);
        $new = DB::table('pk_inspections')->insertGetId(['tenant_id' => $this->tenantId, 'pk_equipment_id' => $this->equipmentId, 'control_date' => '2026-06-01', 'status' => 'uygun_degil', 'findings' => json_encode(['Yeni bulgu'])]);
        DB::table('pk_equipment')->where('id', $this->equipmentId)->update(['last_inspection_id' => $new]);
        // Tesisat: eski genel rapor (sistem + genel bulgu), sonra yalnız sistem raporu.
        $general = DB::table('pk_installation_reports')->insertGetId(['tenant_id' => $this->tenantId, 'pk_installation_id' => $this->installationId, 'control_date' => '2026-03-01', 'status' => 'uygun_degil', 'findings' => json_encode(['Levha yok', 'Eski dolap bulgusu'])]);
        DB::table('pk_installation_report_systems')->insert(['pk_installation_report_id' => $general, 'pk_installation_system_id' => $this->systemId, 'status' => 'uygun_degil', 'findings' => json_encode(['Eski dolap bulgusu'])]);
        $system = DB::table('pk_installation_reports')->insertGetId(['tenant_id' => $this->tenantId, 'pk_installation_id' => $this->installationId, 'control_date' => '2026-07-01', 'status' => 'uygun_degil', 'report_scope' => 'system', 'findings' => json_encode(['Yeni dolap bulgusu'])]);
        DB::table('pk_installation_report_systems')->insert(['pk_installation_report_id' => $system, 'pk_installation_system_id' => $this->systemId, 'status' => 'uygun_degil', 'findings' => json_encode(['Yeni dolap bulgusu'])]);

        $this->artisan('pk:findings-import')->assertSuccessful();
        $this->artisan('pk:findings-import')->assertSuccessful();
        $this->assertEqualsCanonicalizing(['Yeni bulgu', 'Levha yok', 'Yeni dolap bulgusu'], $this->findings()->pluck('text')->all());
        $this->assertSame(3, DB::table('pk_finding_sources')->count());
        $this->assertNotNull($old);
    }
}
