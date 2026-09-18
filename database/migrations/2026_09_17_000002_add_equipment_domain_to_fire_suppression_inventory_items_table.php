<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Program şu an SADECE yangın söndürme ekipmanlarını takip ediyor, ama
    // ilerleyen zamanlarda aynı "periyodik kontrol" mantığı kaldırma-iletme
    // ekipmanları / basınçlı kaplar gibi başka PK uzmanlığı alanlarına da
    // açılacak. Bu kolon, o genişleme geldiğinde hangi kaydın hangi
    // denetim alanından geldiğini ayırt edebilmek için şimdiden eklenir —
    // bugün için tüm kayıtlar 'yangin_sondurme' (bkz. FireSuppressionInventoryItem::DOMAIN_FIRE_SUPPRESSION),
    // yeni alanlar açıldığında kendi domain değerleriyle gelecek.
    public function up(): void
    {
        Schema::table('fire_suppression_inventory_items', function (Blueprint $table) {
            $table->string('equipment_domain')->default('yangin_sondurme')->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('fire_suppression_inventory_items', function (Blueprint $table) {
            $table->dropColumn('equipment_domain');
        });
    }
};
