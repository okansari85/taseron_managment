<?php

use Database\Seeders\PkInstallationCatalogSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * pktakip tesisatları (ekipmanlardan ayrı): tesisat → sistemler → rapor geçmişi. Rapor, lokasyondaki aynı türdeki tesisata eklenir.
 * Genel durum, son / sonraki kontrol son raporun genel sonucundan hesaplanır (saklanmaz).
 * Katalog: tesisat türü periodic_equipment_types (kind=installation), içindeki sistemler periodic_installation_systems.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periodic_installation_systems', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_type_id')->constrained('periodic_equipment_types')->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->string('name');
            // Sistemin ekipmanlarının türü (ör. Yangın Dolapları → yangın dolabı); ekipmansız sistemde boş.
            $table->foreignId('equipment_type_id')->nullable()->constrained('periodic_equipment_types')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pk_installations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            // Boşsa lokasyon geneli (bina), doluysa o işyerine (firma @ lokasyon) ait.
            $table->foreignId('location_business_entity_id')->nullable()->constrained('location_business_entities')->nullOnDelete();
            $table->foreignId('installation_type_id')->constrained('periodic_equipment_types');
            $table->string('name')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'location_id']);
        });

        Schema::create('pk_installation_systems', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pk_installation_id')->constrained('pk_installations')->cascadeOnDelete();
            // Katalogdaki sistem; katalogda karşılığı yoksa boş ve adı raporda yazdığı gibi.
            $table->foreignId('system_id')->nullable()->constrained('periodic_installation_systems')->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('pk_installation_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pk_installation_id')->constrained('pk_installations')->cascadeOnDelete();
            $table->date('control_date');
            // Geçerlilik / gelecek kontrol tarihi: rapordan (yapay zeka) ya da elle.
            $table->date('next_control_date')->nullable();
            // Raporun genel sonucu (sistem sonuçları ayrıca tutulur).
            $table->enum('status', ['uygun', 'uygun_degil']);
            $table->enum('source', ['manual', 'ai'])->default('manual');
            $table->string('report_file');
            $table->string('report_file_name')->nullable();
            $table->string('report_no', 100)->nullable();
            $table->string('report_hash', 64)->nullable();
            $table->string('inspection_body')->nullable();
            $table->text('conclusion')->nullable();
            $table->json('findings')->nullable();
            $table->text('notes')->nullable();
            // Yapay zeka analizi (kriterler hariç) + tablo adımı: kayıt anındaki okuma.
            $table->json('analysis')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['pk_installation_id', 'control_date']);
            $table->index(['tenant_id', 'report_no']);
        });

        Schema::create('pk_installation_report_systems', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pk_installation_report_id')->constrained('pk_installation_reports')->cascadeOnDelete();
            $table->foreignId('pk_installation_system_id')->constrained('pk_installation_systems')->cascadeOnDelete();
            // Raporda sonucu yazmıyorsa boş.
            $table->enum('status', ['uygun', 'uygun_degil'])->nullable();
            // Rapordaki sistem ad(lar)ı: ekipman satırları ve bulgular bu adlarla analizde aranır.
            $table->json('source_names')->nullable();
            $table->json('findings')->nullable();
            // Rapordaki ekipman grupları: [{equipment_name, total, uygun, uygun_degil, unknown}].
            $table->json('equipment_summary')->nullable();
            $table->timestamps();

            $table->unique(['pk_installation_report_id', 'pk_installation_system_id'], 'pk_inst_report_system_unique');
        });

        (new PkInstallationCatalogSeeder())->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('pk_installation_report_systems');
        Schema::dropIfExists('pk_installation_reports');
        Schema::dropIfExists('pk_installation_systems');
        Schema::dropIfExists('pk_installations');
        Schema::dropIfExists('periodic_installation_systems');
        DB::table('periodic_equipment_types')->where('slug', 'yangin-tesisati')->delete();
    }
};
