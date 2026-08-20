<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('location_business_entities')) {
            return;
        }

        if (Schema::hasColumn('location_business_entities', 'brand_id')) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->dropForeign(['brand_id']);
                $table->dropColumn('brand_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('location_business_entities')) {
            return;
        }

        if (! Schema::hasColumn('location_business_entities', 'brand_id')) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->foreignId('brand_id')
                    ->nullable()
                    ->after('business_entity_id')
                    ->constrained('brands')
                    ->restrictOnDelete();
            });
        }
    }
};
