<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emergency_equipment_types', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('tenant_id');
            $table->foreign('parent_id', 'eet_parent_id_fk')
                ->references('id')->on('emergency_equipment_types')
                ->restrictOnDelete();
            $table->index(['tenant_id', 'parent_id'], 'eet_tenant_parent_idx');
            $table->decimal('capacity_kg', 8, 2)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('emergency_equipment_types', function (Blueprint $table) {
            $table->dropColumn('capacity_kg');
            $table->dropIndex('eet_tenant_parent_idx');
            $table->dropForeign('eet_parent_id_fk');
            $table->dropColumn('parent_id');
        });
    }
};
