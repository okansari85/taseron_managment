<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ekipmanı pasife alma (is_active zaten var): ne zaman ve neden (hurdaya ayrıldı, satıldı, başka yere taşındı, diğer)
 * + not. Pasif ekipmanın geçmişi kalır; kontrol eklenmez, kalan gün hesabına ve tesisat listesine girmez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pk_equipment', function (Blueprint $table) {
            $table->date('deactivated_at')->nullable()->after('is_active');
            $table->string('deactivation_reason', 20)->nullable()->after('deactivated_at');
            $table->text('deactivation_note')->nullable()->after('deactivation_reason');
        });
    }

    public function down(): void
    {
        Schema::table('pk_equipment', function (Blueprint $table) {
            $table->dropColumn(['deactivated_at', 'deactivation_reason', 'deactivation_note']);
        });
    }
};
