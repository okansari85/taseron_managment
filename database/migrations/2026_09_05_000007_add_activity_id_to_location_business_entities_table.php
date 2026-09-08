<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('location_business_entities', function (Blueprint $table) {
            $table->foreignId('activity_id')
                ->nullable()
                ->after('sub_activity')
                ->constrained('activities')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('location_business_entities', function (Blueprint $table) {
            $table->dropForeign(['activity_id']);
            $table->dropColumn('activity_id');
        });
    }
};
