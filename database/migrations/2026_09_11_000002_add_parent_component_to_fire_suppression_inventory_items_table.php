<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Bazı ana sistem bileşenleri (örn. Yangın Pompa Dairesi) TEK bir bütün
// olarak değerlendirilir ama içinde ayrı ayrı KAYITLI alt-ekipmanlar
// barındırabilir (Pompa 1, Pompa 2, Jokey Pompa — bkz. proje mimari kararı:
// "pompa ayrı bir ana sistem bileşeni değildir, Yangın Pompa Dairesi'nin
// içindeki ekipmandır"). Bu ilişkiyi kaybetmeden temsil etmek için
// self-referencing nullable bir üst-bileşen bağlantısı eklendi — dolap/
// hidrant gibi PER-UNIT bileşenler bunu hiç kullanmaz (null kalır).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fire_suppression_inventory_items', function (Blueprint $table) {
            $table->foreignId('parent_component_id')
                ->nullable()
                ->after('id')
                ->constrained('fire_suppression_inventory_items', 'id', 'fsi_items_parent_foreign')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fire_suppression_inventory_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_component_id');
        });
    }
};
