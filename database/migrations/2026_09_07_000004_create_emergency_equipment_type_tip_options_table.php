<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emergency_equipment_types', function (Blueprint $table) {
            $table->string('tip')->nullable()->after('capacity_kg');
        });

        Schema::create('emergency_equipment_type_tip_options', function (Blueprint $table) {
            $table->id();

            $table->foreignId('equipment_type_id');
            $table->foreign('equipment_type_id', 'eetto_equipment_type_id_fk')
                ->references('id')->on('emergency_equipment_types')
                ->cascadeOnDelete();

            $table->string('label');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['equipment_type_id', 'label'], 'eetto_type_label_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_equipment_type_tip_options');

        Schema::table('emergency_equipment_types', function (Blueprint $table) {
            $table->dropColumn('tip');
        });
    }
};
