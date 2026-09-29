<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Katalog dışı teknik özellik için kataloğa ekleme talebi (uzman → yetkili onayı). Talep edilen değer, onaylanana kadar
// ekipmanın özellik kayıtlarında "onay bekliyor" (accepted=false, spec_request_id) olarak durur.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periodic_equipment_spec_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('equipment_type_id')->constrained('periodic_equipment_types')->cascadeOnDelete();
            $table->foreignId('pk_equipment_id')->nullable()->constrained('pk_equipment')->nullOnDelete();
            $table->foreignId('pk_inspection_id')->nullable()->constrained('pk_inspections')->nullOnDelete();
            $table->string('name');
            $table->string('unit', 30)->nullable();
            $table->string('raw_label')->nullable();
            $table->text('raw_value')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->index();
            $table->foreignId('spec_id')->nullable()->constrained('periodic_equipment_specs')->nullOnDelete();
            $table->text('decision_note')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::table('pk_equipment_property_values', function (Blueprint $table) {
            $table->foreignId('spec_request_id')->nullable()->after('pk_inspection_id')->constrained('periodic_equipment_spec_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pk_equipment_property_values', function (Blueprint $table) {
            $table->dropConstrainedForeignId('spec_request_id');
        });
        Schema::dropIfExists('periodic_equipment_spec_requests');
    }
};
