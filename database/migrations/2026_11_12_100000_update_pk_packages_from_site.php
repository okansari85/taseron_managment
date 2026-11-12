<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// pktakip (2026-11-12): paketler pktakip.com/paketler ile aynı olur (kullanıcı: "sitedeki fiyatlar doğru").
// İlk migration'daki taslak kredi/aylık fiyat değişir, yıllık fiyat girilir. Paketteki abonelikler yeni aylık krediyi alır.
return new class extends Migration
{
    // [hesap türü, ad, aylık kredi, aylık fiyat, yıllık fiyat]
    private const SITE = [
        ['expert', 'Mini', 100, 100, 999], ['expert', 'Standart', 250, 249, 2750], ['expert', 'Plus', 1000, 449, 4500],
        ['osgb', 'Başlangıç', 3000, 1999, 21989], ['osgb', 'Pro', 10000, 4999, 54989],
        ['corporate', 'Business', 2500, 1999, 21989], ['corporate', 'Enterprise', 10000, 3999, 43989],
    ];

    // 2026_10_03_130000 taslağı (geri almak için).
    private const DRAFT = [
        ['expert', 'Mini', 20, 100, null], ['expert', 'Standart', 50, 249, null], ['expert', 'Plus', 100, 449, null],
        ['osgb', 'Başlangıç', 250, 899, null], ['osgb', 'Pro', 500, 1499, null],
        ['corporate', 'Business', 1000, 2499, null], ['corporate', 'Enterprise', 2500, 4999, null],
    ];

    public function up(): void
    {
        $this->apply(self::SITE);
    }

    public function down(): void
    {
        $this->apply(self::DRAFT);
    }

    private function apply(array $packages): void
    {
        foreach ($packages as [$type, $name, $credits, $monthly, $yearly]) {
            $id = DB::table('pk_packages')->where('account_type', $type)->where('name', $name)->value('id');
            if (!$id) {
                continue;
            }
            DB::table('pk_packages')->where('id', $id)
                ->update(['monthly_credits' => $credits, 'monthly_price' => $monthly, 'yearly_price' => $yearly, 'updated_at' => now()]);
            DB::table('pk_subscriptions')->where('mode', 'package')->where('pk_package_id', $id)
                ->update(['monthly_credits' => $credits, 'updated_at' => now()]);
        }
    }
};
