<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KSY-SIS yapısı (17 Kas 2026): firma ve işyeri bir ana firmaya (customers) aittir; lokasyon hesabın kaydıdır, ana firmalara
 * ağaç üzerinden bağlanır. Aynı lokasyonda birden çok ana firma olabilir; her biri yalnız kendi işyerlerini görür.
 * Alt tesis: lokasyonun bölümü (bina, hangar…); aynı firmanın aynı lokasyondaki işyerleri alt tesislerle ayrılır.
 * Mevcut kayıtlar: işyeri, lokasyonunun bağlı olduğu ilk ana firmaya; firma, ilk işyerinin ana firmasına yazılır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sub_facilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['location_id', 'name']);
        });

        Schema::table('business_entities', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('tenant_id')->constrained('customers')->nullOnDelete();
        });

        Schema::table('location_business_entities', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('business_entity_id')->constrained('customers')->nullOnDelete();
            $table->foreignId('sub_facility_id')->nullable()->after('customer_id')->constrained('sub_facilities')->nullOnDelete();
        });

        $this->backfill();
    }

    private function backfill(): void
    {
        // Düğüm → ana firma: customer_organization'daki düğüm ya da onun en yakın üstü.
        $parents = DB::table('organizations')->pluck('parent_id', 'id');
        $direct = DB::table('customer_organization')->orderBy('id')->get(['organization_id', 'customer_id'])
            ->groupBy('organization_id')->map(fn ($rows) => (int) $rows->first()->customer_id);
        $customerOf = function (int $organizationId) use ($parents, $direct): ?int {
            for ($id = $organizationId, $guard = 0; $id && $guard < 50; $id = (int) ($parents[$id] ?? 0), $guard++) {
                if (isset($direct[$id])) {
                    return $direct[$id];
                }
            }

            return null;
        };

        $locationCustomer = [];
        foreach (DB::table('organization_locations')->orderBy('id')->get(['organization_id', 'location_id']) as $link) {
            $locationCustomer[$link->location_id] ??= $customerOf((int) $link->organization_id);
        }

        foreach (DB::table('location_business_entities')->orderBy('id')->get(['id', 'location_id', 'business_entity_id']) as $workplace) {
            $customerId = $locationCustomer[$workplace->location_id] ?? null;
            if (! $customerId) {
                continue;
            }
            DB::table('location_business_entities')->where('id', $workplace->id)->update(['customer_id' => $customerId]);
            DB::table('business_entities')->where('id', $workplace->business_entity_id)->whereNull('customer_id')->update(['customer_id' => $customerId]);
        }
    }

    public function down(): void
    {
        Schema::table('location_business_entities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sub_facility_id');
            $table->dropConstrainedForeignId('customer_id');
        });
        Schema::table('business_entities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });
        Schema::dropIfExists('sub_facilities');
    }
};
