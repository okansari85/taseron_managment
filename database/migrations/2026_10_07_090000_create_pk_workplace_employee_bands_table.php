<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * İşyerinin çalışan sayısı aralığı (pktakip): 6331 idari para cezası tutarı çalışan sayısına göre değişir
 * (10'dan az / 10–49 / 50 ve üzeri). İşyeri açılırken seçilir; işyeri silinince kaydı da silinir. Mevcut tablolara dokunulmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pk_workplace_employee_bands', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('location_business_entity_id');
            $table->string('band', 10); // lt10 | 10_49 | 50_plus
            $table->timestamps();

            $table->unique('location_business_entity_id', 'pweb_workplace_unique');
            $table->foreign('location_business_entity_id', 'pweb_workplace_fk')->references('id')->on('location_business_entities')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pk_workplace_employee_bands');
    }
};
