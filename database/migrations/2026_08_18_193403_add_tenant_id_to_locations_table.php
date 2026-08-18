<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->foreignId('tenant_id')
                ->nullable()
                ->after('id')
                ->constrained('tenants')
                ->cascadeOnDelete();
        });

        DB::table('locations')
            ->whereNull('tenant_id')
            ->update([
                'tenant_id' => 1,
            ]);

        Schema::table('locations', function (Blueprint $table) {
            $table->foreignId('tenant_id')
                ->nullable(false)
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
