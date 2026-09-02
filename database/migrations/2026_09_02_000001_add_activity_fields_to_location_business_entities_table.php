<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('location_business_entities', function (Blueprint $table): void {
            $table->string('activity', 255)->nullable()->after('operational_region_id');
            $table->string('sub_activity', 255)->nullable()->after('activity');
        });
    }

    public function down(): void
    {
        Schema::table('location_business_entities', function (Blueprint $table): void {
            $table->dropColumn(['activity', 'sub_activity']);
        });
    }
};
