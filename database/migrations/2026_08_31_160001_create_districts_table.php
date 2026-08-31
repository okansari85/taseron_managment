<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('districts', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->foreignId('city_id')->constrained('cities')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['city_id', 'name']);
            $table->index('city_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('districts');
    }
};
