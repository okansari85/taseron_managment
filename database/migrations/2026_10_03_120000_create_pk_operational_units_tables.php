<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operasyonel birim (pktakip): işyerinin (firmanın lokasyondaki kaydı) içinde ayrı yönetilen birim, ör. Arçelik Pazarlama
 * Beylikdüzü işyerinde "Beyaz Eşya Depo" ve "Müşteri Hizmetleri". Aynı firma, SGK ve uzman; ayrı sorumlu ve yer. Birim fiziksel
 * değildir; kullandığı alanlar (bina, kat, depo…) ayrıca seçilir (birden çok). Ekipman firmaya ait, birimi isteğe bağlı.
 * Alan yalnızca fiziksel yer olarak kalır; türlere "Kat" eklenir. İsteğe bağlıdır: birimi olmayan işyeri bugünkü gibi çalışır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pk_operational_units', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('location_business_entity_id');
            $table->string('name');
            $table->string('manager')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'location_business_entity_id'], 'pou_tenant_workplace_index');
            $table->foreign('tenant_id', 'pou_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('location_business_entity_id', 'pou_workplace_fk')->references('id')->on('location_business_entities')->cascadeOnDelete();
        });

        Schema::create('pk_operational_unit_areas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pk_operational_unit_id');
            $table->unsignedBigInteger('pk_area_id');
            $table->timestamps();

            $table->unique(['pk_operational_unit_id', 'pk_area_id'], 'poua_unit_area_unique');
            $table->foreign('pk_operational_unit_id', 'poua_unit_fk')->references('id')->on('pk_operational_units')->cascadeOnDelete();
            $table->foreign('pk_area_id', 'poua_area_fk')->references('id')->on('pk_areas')->cascadeOnDelete();
        });

        Schema::table('pk_equipment', function (Blueprint $table) {
            $table->unsignedBigInteger('pk_operational_unit_id')->nullable()->after('pk_area_id');
            $table->foreign('pk_operational_unit_id', 'pke_unit_fk')->references('id')->on('pk_operational_units')->nullOnDelete();
        });

        if (!DB::table('pk_area_types')->whereNull('tenant_id')->where('name', 'Kat')->exists()) {
            DB::table('pk_area_types')->insert(['tenant_id' => null, 'name' => 'Kat', 'kind' => 'operational', 'sort_order' => 15, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('pk_equipment', function (Blueprint $table) {
            $table->dropForeign('pke_unit_fk');
            $table->dropColumn('pk_operational_unit_id');
        });
        Schema::dropIfExists('pk_operational_unit_areas');
        Schema::dropIfExists('pk_operational_units');
        DB::table('pk_area_types')->whereNull('tenant_id')->where('name', 'Kat')->delete();
    }
};
