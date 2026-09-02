<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('location_business_entities', function (Blueprint $table): void {
            $table->string('nace_code')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('location_business_entities', function (Blueprint $table): void {
            $table->string('nace_code')->nullable(false)->change();
        });
    }
};
