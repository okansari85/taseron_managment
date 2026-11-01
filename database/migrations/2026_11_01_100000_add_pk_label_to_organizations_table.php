<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pktakip: organizasyon düğümünün isteğe bağlı etiketi (Holding, Marka...). organizations.type kullanılmaz: "company" / "brand"
// türü düğümün adını ve silinmesini kilitler; etiket yalnız gösterim içindir.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('pk_label', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('pk_label');
        });
    }
};
