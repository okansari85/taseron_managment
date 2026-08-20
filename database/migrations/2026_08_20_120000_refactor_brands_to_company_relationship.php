<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_brands', function (Blueprint $table) {
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->foreignId('brand_id')
                ->constrained('brands')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique([
                'company_id',
                'brand_id',
            ]);
        });

        Schema::table('brands', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });

        Schema::dropIfExists('brand_locations');
    }

    public function down(): void
    {
        Schema::create('brand_locations', function (Blueprint $table) {
            $table->foreignId('brand_id')
                ->constrained('brands')
                ->cascadeOnDelete();

            $table->foreignId('location_id')
                ->constrained('locations')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique([
                'brand_id',
                'location_id',
            ]);
        });

        Schema::table('brands', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->nullable()
                ->constrained('organizations')
                ->nullOnDelete();
        });

        Schema::dropIfExists('company_brands');
    }
};
