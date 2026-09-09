<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fire_suppression_report_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_id')
                ->constrained('fire_suppression_reports', 'id', 'fsrf_report_foreign')
                ->cascadeOnDelete();
            $table->string('category')->nullable();
            $table->string('control_item')->nullable();
            $table->text('description');
            // all | specific | area | unknown — section 18.
            $table->string('scope')->default('unknown');
            $table->string('area_note')->nullable();
            $table->string('status')->default('open'); // open | closed
            $table->timestamps();
        });

        Schema::create('fire_suppression_report_finding_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finding_id')
                ->constrained('fire_suppression_report_findings', 'id', 'fsrfi_finding_foreign')
                ->cascadeOnDelete();
            $table->foreignId('inventory_item_id')
                ->constrained('fire_suppression_inventory_items', 'id', 'fsrfi_item_foreign')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['finding_id', 'inventory_item_id'], 'fsrfi_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fire_suppression_report_finding_items');
        Schema::dropIfExists('fire_suppression_report_findings');
    }
};
