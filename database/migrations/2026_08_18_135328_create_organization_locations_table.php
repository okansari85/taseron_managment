<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_locations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->foreignId('location_id')
                ->constrained('locations')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique([
                'organization_id',
                'location_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_locations');
    }
};