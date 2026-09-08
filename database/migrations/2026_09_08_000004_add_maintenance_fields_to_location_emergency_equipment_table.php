<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('location_emergency_equipment', function (Blueprint $table) {
            $table->date('last_fill_date')->nullable()->after('install_date');
            $table->date('last_annual_maintenance_date')->nullable()->after('last_fill_date');
            $table->date('next_annual_maintenance_date')->nullable()->after('last_annual_maintenance_date');
            $table->string('service_company')->nullable()->after('next_annual_maintenance_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('location_emergency_equipment', function (Blueprint $table) {
            $table->dropColumn(['last_fill_date', 'last_annual_maintenance_date', 'next_annual_maintenance_date', 'service_company']);
        });
    }
};
