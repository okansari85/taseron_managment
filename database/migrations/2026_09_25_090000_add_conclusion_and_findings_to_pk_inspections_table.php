<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pktakip kontrol kaydı: raporun sonuç ve kanaati + madde madde bulgular (elle ya da yapay zeka ile).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pk_inspections', function (Blueprint $table) {
            $table->text('conclusion')->nullable()->after('note');
            $table->json('findings')->nullable()->after('conclusion');
        });
    }

    public function down(): void
    {
        Schema::table('pk_inspections', function (Blueprint $table) {
            $table->dropColumn(['conclusion', 'findings']);
        });
    }
};
