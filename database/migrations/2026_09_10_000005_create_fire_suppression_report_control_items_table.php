<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fire_suppression_report_control_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_id')
                ->constrained('fire_suppression_reports', 'id', 'fsrci_report_foreign')
                ->cascadeOnDelete();
            $table->foreignId('template_id')
                ->nullable()
                ->constrained('fire_suppression_control_item_templates', 'id', 'fsrci_template_foreign')
                ->nullOnDelete();
            $table->string('category');
            $table->string('code', 20)->nullable();
            $table->string('section')->nullable();
            $table->string('title');
            // uygun | uygun_degil | uygulanamiyor
            $table->string('status')->default('uygun');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fire_suppression_report_control_items');
    }
};
