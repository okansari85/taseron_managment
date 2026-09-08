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
        Schema::table('location_business_entities', function (Blueprint $table) {
            $table->string('address')->nullable()->after('sgk_workplace_number');
            $table->boolean('is_active')->default(true)->after('address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('location_business_entities', function (Blueprint $table) {
            $table->dropColumn(['address', 'is_active']);
        });
    }
};
