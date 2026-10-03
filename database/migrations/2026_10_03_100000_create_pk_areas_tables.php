<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * pktakip alanları: lokasyonun içindeki yerler. Operasyonel alan (depo, bina, ofis…; hizmet veren firmanın lokasyondaki kaydına
 * bağlı) ya da ortak alan (yemekhane, tuvalet, revir…; firmasız). Alt alan tek kat (parent_id). Alan türleri çoğaltılabilir:
 * tenant_id boş olanlar herkes için hazır türler, dolu olanlar hesabın eklediği türler. İsteğe bağlıdır: alanı olmayan lokasyon
 * bugünkü gibi çalışır. Mevcut tablolara dokunulmaz (Taşeron'un operational_regions tablosu kullanılmaz).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pk_area_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('name');
            $table->enum('kind', ['operational', 'common']);
            $table->unsignedInteger('sort_order')->default(100);
            $table->timestamps();

            $table->index('tenant_id', 'pat_tenant_index');
            $table->foreign('tenant_id', 'pat_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('pk_areas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('pk_area_type_id');
            $table->string('name');
            // Operasyonel alanda hizmet veren firmanın lokasyondaki kaydı (işyeri); ortak alanda boş.
            $table->unsignedBigInteger('location_business_entity_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'location_id'], 'pa_tenant_location_index');
            $table->foreign('tenant_id', 'pa_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('location_id', 'pa_location_fk')->references('id')->on('locations')->cascadeOnDelete();
            $table->foreign('parent_id', 'pa_parent_fk')->references('id')->on('pk_areas')->restrictOnDelete();
            $table->foreign('pk_area_type_id', 'pa_type_fk')->references('id')->on('pk_area_types')->restrictOnDelete();
            $table->foreign('location_business_entity_id', 'pa_lbe_fk')->references('id')->on('location_business_entities')->nullOnDelete();
        });

        $now = now();
        $types = [
            ['Depo', 'operational'], ['Bina', 'operational'], ['Ofis', 'operational'], ['Üretim', 'operational'], ['Mağaza', 'operational'],
            ['Yemekhane', 'common'], ['Tuvalet', 'common'], ['Revir', 'common'], ['Otopark', 'common'], ['Soyunma Odası', 'common'],
        ];
        DB::table('pk_area_types')->insert(array_map(fn (array $type, int $index) => [
            'tenant_id' => null, 'name' => $type[0], 'kind' => $type[1], 'sort_order' => ($index + 1) * 10, 'created_at' => $now, 'updated_at' => $now,
        ], $types, array_keys($types)));
    }

    public function down(): void
    {
        Schema::dropIfExists('pk_areas');
        Schema::dropIfExists('pk_area_types');
    }
};
