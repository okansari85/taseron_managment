<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contractors', function (Blueprint $table) {
            $table->id();

            $table->foreignId('business_entity_id')
                ->constrained('business_entities')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique('business_entity_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contractors');
    }
};
