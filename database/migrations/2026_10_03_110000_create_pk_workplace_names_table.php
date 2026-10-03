<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * İşyeri adı (isteğe bağlı): firmanın lokasyondaki kaydının (işyeri) kendi adı, ör. Eskişehir'de Arçelik A.Ş.'nin iki işyeri
 * "Eskişehir Buzdolabı İşletmesi" ve "Eskişehir Kompresör İşletmesi". Firma tüzel kişidir, işyeri onun lokasyondaki kaydı
 * (SGK, NACE, tehlike sınıfı, uzman), alan fiziksel yerdir. İşyeri silinince adı da silinir. Mevcut tablolara dokunulmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pk_workplace_names', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('location_business_entity_id');
            $table->string('name');
            $table->timestamps();

            $table->unique('location_business_entity_id', 'pwn_workplace_unique');
            $table->foreign('location_business_entity_id', 'pwn_workplace_fk')->references('id')->on('location_business_entities')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pk_workplace_names');
    }
};
