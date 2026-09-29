<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Firma ünvanları (ör. "Arçelik A.Ş."): hesapta bir kez tanımlanır, birden çok firmaya (işletmeye) bağlanır. Taşeron'un
// firma tablolarına dokunulmaz.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pk_company_titles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('pk_company_title_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_entity_id')->unique()->constrained('business_entities')->cascadeOnDelete();
            $table->foreignId('pk_company_title_id')->constrained('pk_company_titles')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pk_company_title_links');
        Schema::dropIfExists('pk_company_titles');
    }
};
