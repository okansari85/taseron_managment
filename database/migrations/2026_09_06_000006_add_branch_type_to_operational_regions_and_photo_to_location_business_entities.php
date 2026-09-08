<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operational_regions')) {
            DB::statement("ALTER TABLE operational_regions MODIFY type ENUM('facility', 'warehouse', 'business', 'depot', 'office', 'store', 'branch') NOT NULL");
        }

        if (Schema::hasTable('location_business_entities') && ! Schema::hasColumn('location_business_entities', 'photo_path')) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->string('photo_path')->nullable()->after('sgk_workplace_number');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('location_business_entities') && Schema::hasColumn('location_business_entities', 'photo_path')) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->dropColumn('photo_path');
            });
        }

        if (Schema::hasTable('operational_regions')) {
            DB::statement("ALTER TABLE operational_regions MODIFY type ENUM('facility', 'warehouse', 'business', 'depot', 'office', 'store') NOT NULL");
        }
    }
};
