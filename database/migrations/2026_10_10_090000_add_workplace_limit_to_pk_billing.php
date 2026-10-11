<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// pktakip (2026-10-10): paket limiti ekipmandan işyerine geçer (her location_business_entity bir işyeri; pasif sayılmaz).
// Modüller (Periyodik Kontrol, OSGB Arşivi) aynı işyerine bağlı olduğu için tek limit. equipment_limit kalır, artık kullanılmaz.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pk_packages', function (Blueprint $table) {
            $table->unsignedInteger('workplace_limit')->nullable()->after('equipment_limit');
        });
        Schema::table('pk_subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('workplace_limit')->nullable()->after('equipment_limit');
        });

        // Kullanıcının onayladığı limitler (rakiplerin kullanıcı başı fiyatına göre); listede olmayan paketler sınırsız.
        $limits = [['expert', 'Mini', 10], ['expert', 'Standart', 25], ['osgb', 'Başlangıç', 75]];
        foreach ($limits as [$type, $name, $limit]) {
            DB::table('pk_packages')->where('account_type', $type)->where('name', $name)->update(['workplace_limit' => $limit]);
        }

        // Mevcut abonelikler: paketteki hesap paketin limitini, deneme 3, paketsiz 0 alır; sınırsız hesaplar sınırsız kalır.
        foreach (DB::table('pk_subscriptions')->where('mode', 'package')->whereNotNull('pk_package_id')->get(['id', 'pk_package_id']) as $row) {
            DB::table('pk_subscriptions')->where('id', $row->id)
                ->update(['workplace_limit' => DB::table('pk_packages')->where('id', $row->pk_package_id)->value('workplace_limit')]);
        }
        DB::table('pk_subscriptions')->where('mode', 'promo')->update(['workplace_limit' => 3]);
        DB::table('pk_subscriptions')->where('mode', 'none')->update(['workplace_limit' => 0]);
    }

    public function down(): void
    {
        Schema::table('pk_subscriptions', function (Blueprint $table) {
            $table->dropColumn('workplace_limit');
        });
        Schema::table('pk_packages', function (Blueprint $table) {
            $table->dropColumn('workplace_limit');
        });
    }
};
