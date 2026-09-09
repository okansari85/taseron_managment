<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fire_suppression_reports', function (Blueprint $table) {
            $table->string('report_no')->nullable()->after('location_business_entity_id');
            $table->string('inspection_company_name')->nullable()->after('overall_result');
        });
    }

    public function down(): void
    {
        Schema::table('fire_suppression_reports', function (Blueprint $table) {
            $table->dropColumn(['report_no', 'inspection_company_name']);
        });
    }
};
