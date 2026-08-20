<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('operational_regions')) {
            return;
        }

        DB::statement("ALTER TABLE operational_regions MODIFY type ENUM('facility', 'warehouse', 'business', 'depot', 'office', 'store') NOT NULL");
    }

    public function down(): void
    {
        if (! Schema::hasTable('operational_regions')) {
            return;
        }

        DB::statement("ALTER TABLE operational_regions MODIFY type ENUM('facility', 'warehouse', 'business') NOT NULL");
    }
};
