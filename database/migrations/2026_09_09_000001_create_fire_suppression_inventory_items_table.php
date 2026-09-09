<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fire_suppression_inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_business_entity_id')
                ->constrained('location_business_entities', 'id', 'fsi_items_lbe_foreign')
                ->cascadeOnDelete();
            // yangin_dolabi | sprinkler | hidrant | yangin_pompasi | su_deposu | gazli_sondurme | diger
            $table->string('category');
            $table->string('code')->nullable();
            $table->string('location_note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('last_control_date')->nullable();
            $table->date('next_control_date')->nullable();
            // uygun | uygun_degil | null (henüz kontrol edilmedi / rapor bağlı değil)
            $table->string('compliance_status')->nullable();
            $table->unsignedInteger('open_nonconformity_count')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fire_suppression_inventory_items');
    }
};
