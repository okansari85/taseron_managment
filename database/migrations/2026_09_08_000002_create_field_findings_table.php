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
        Schema::create('field_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_business_entity_id')
                ->constrained('location_business_entities')
                ->cascadeOnDelete();
            $table->enum('category', ['yangin_guvenligi', 'acil_cikis', 'yangin_kapisi', 'kacis_yolu', 'diger']);
            $table->string('location_note')->nullable();
            $table->text('description')->nullable();
            $table->enum('severity', ['dusuk', 'orta', 'yuksek', 'kritik'])->default('orta');
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->foreignId('reported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('field_finding_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_finding_id')->constrained('field_findings', 'id', 'ff_photos_ff_id_fk')->cascadeOnDelete();
            $table->string('photo_path');
            $table->unsignedInteger('order_no')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('field_finding_photos');
        Schema::dropIfExists('field_findings');
    }
};
