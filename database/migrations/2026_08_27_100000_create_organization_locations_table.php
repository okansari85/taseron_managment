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
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'organization_id', 'location_id'],
                'organization_locations_unique'
            );
            $table->index(['tenant_id', 'organization_id']);
            $table->index(['tenant_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_locations');
    }
};
