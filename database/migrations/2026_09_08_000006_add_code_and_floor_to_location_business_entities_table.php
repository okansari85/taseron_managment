<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('location_business_entities', function (Blueprint $table) {
            $table->string('code')->nullable()->after('business_entity_id');
            $table->string('floor')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('location_business_entities', function (Blueprint $table) {
            $table->dropColumn(['code', 'floor']);
        });
    }
};
