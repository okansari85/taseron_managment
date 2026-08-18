<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('business_entity_id')
                ->nullable()
                ->after('id')
                ->constrained('business_entities')
                ->restrictOnDelete();

            $table->unique('business_entity_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign([
                'business_entity_id',
            ]);

            $table->dropUnique([
                'business_entity_id',
            ]);

            $table->dropColumn('business_entity_id');
        });
    }
};
