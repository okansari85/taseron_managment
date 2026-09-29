<?php

use Database\Seeders\PkInstallationCatalogSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * pktakip tesisat kataloğu: sistemin ekipman türleri (ör. Yangın Pompa İstasyonu → Yangın Pompası, Pompa Kontrol Panosu,
 * Basınç Tankı). Türler Ekipmanlar'daki kategorilerde (Yangın Ekipmanları) diğer türler gibi durur; hangi sisteme ait
 * oldukları yalnızca burada, Tesisatlar sayfası için.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periodic_system_equipment_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_system_id')->constrained('periodic_installation_systems', 'id', 'pset_system_fk')->cascadeOnDelete();
            $table->foreignId('equipment_type_id')->constrained('periodic_equipment_types', 'id', 'pset_type_fk')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['installation_system_id', 'equipment_type_id'], 'pset_system_type_unique');
        });

        (new PkInstallationCatalogSeeder())->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('periodic_system_equipment_types');
    }
};
