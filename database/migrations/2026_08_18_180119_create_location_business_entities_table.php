<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_business_entities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('location_id')
                ->constrained('locations')
                ->cascadeOnDelete();

            $table->foreignId('business_entity_id')
                ->constrained('business_entities')
                ->cascadeOnDelete();

            $table->string('nace_code');

            $table->string('hazard_class');

            $table->string('sgk_workplace_number')
                ->nullable();

            $table->timestamps();

            $table->unique([
                'location_id',
                'business_entity_id',
            ]);

            $table->index([
                'business_entity_id',
                'location_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_business_entities');
    }
};
