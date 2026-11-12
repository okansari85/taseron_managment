<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pktakip Bulgu Takip (2026-11-12): raporlardaki her bulgu takip edilen bir kayıt olur. Bulgu ekipmana ya da tesisata (genel ya da
// sistemine, varsa bileşenine) bağlıdır; aynı bulgu birden çok raporda görülebilir (pk_finding_sources). Geçmiş ve kanıt dosyaları
// pk_finding_events'te. Kaynak raporlar silinse de üzerinde işlem yapılmış bulgu kalır (kaynağa yabancı anahtar yok).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pk_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('pk_equipment_id')->nullable()->index();
            $table->foreignId('pk_installation_id')->nullable()->index();
            $table->foreignId('pk_installation_system_id')->nullable()->index();
            $table->string('component')->nullable();
            $table->foreignId('location_id')->nullable()->index();
            $table->foreignId('location_business_entity_id')->nullable()->index();
            $table->text('text');
            $table->char('text_key', 40)->index();
            $table->text('work')->nullable();
            $table->foreignId('responsible_user_id')->nullable()->index();
            $table->date('due_date')->nullable();
            $table->date('recheck_date')->nullable();
            $table->string('status', 20)->default('acik');
            $table->date('last_seen_date')->nullable();
            $table->string('close_suggested_by', 60)->nullable();
            $table->date('close_suggested_date')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('pk_finding_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pk_finding_id')->constrained('pk_findings')->cascadeOnDelete();
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_id');
            $table->date('control_date')->nullable();
            $table->timestamps();

            $table->unique(['pk_finding_id', 'source_type', 'source_id'], 'pfs_unique');
            $table->index(['source_type', 'source_id'], 'pfs_source');
        });

        Schema::create('pk_finding_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pk_finding_id')->constrained('pk_findings')->cascadeOnDelete();
            $table->string('type', 30);
            $table->text('note')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamps();

            $table->index(['pk_finding_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pk_finding_events');
        Schema::dropIfExists('pk_finding_sources');
        Schema::dropIfExists('pk_findings');
    }
};
