<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('location_business_entity_brands')) {
            return;
        }

        Schema::create('location_business_entity_brands', function (Blueprint $table) {
            $table->id();

            $table->foreignId('location_business_entity_id')
                ->constrained('location_business_entities')
                ->cascadeOnDelete();

            $table->foreignId('brand_id')
                ->constrained('brands')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique([
                'location_business_entity_id',
                'brand_id',
            ], 'lbe_brand_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_business_entity_brands');
    }
};
