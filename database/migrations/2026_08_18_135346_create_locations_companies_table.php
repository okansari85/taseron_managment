<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations_companies', function (Blueprint $table) {
            $table->id();

            $table->foreignId('location_id')
                ->constrained('locations')
                ->cascadeOnDelete();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->string('nace_code')->nullable();

            $table->string('hazard_class')->nullable();

            $table->string('sgk_workplace_number')->nullable();

            $table->timestamps();

            $table->unique([
                'location_id',
                'company_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations_companies');
    }
};