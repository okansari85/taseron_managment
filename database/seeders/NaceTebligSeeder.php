<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * İş Sağlığı ve Güvenliğine İlişkin İşyeri Tehlike Sınıfları Tebliği'nin güncel tam NACE listesi (2144 kayıt; RG 1/4/2026
 * değişiklikleri dahil). Kaynak: kullanıcının verdiği nace_kodlari_temiz.xlsx (20 Ekim 2026) → database/data/nace_hazard_classes.json.
 * Temizlik: "Çok Tehlikeli *" → "Çok Tehlikeli"; kodun yanındaki "(Değişik satır: RG-…)" / "(Ek satır: …)" notları silindi.
 * Tablonun içeriği bu listeyle tamamen değiştirilir (eski kısmi NaceHazardClassSeeder verisi ve snake_case sınıf yazımları gider).
 * Tabloya bağlı başka tablo yok; işyerleri NACE kodunu metin olarak tutar. Çalıştırma: php artisan db:seed --class=NaceTebligSeeder --force
 */
class NaceTebligSeeder extends Seeder
{
    private const SINIFLAR = ['Az Tehlikeli', 'Tehlikeli', 'Çok Tehlikeli'];

    public function run(): void
    {
        $rows = json_decode(file_get_contents(database_path('data/nace_hazard_classes.json')), true, flags: JSON_THROW_ON_ERROR);

        // Liste eksik ya da bozuksa tablo değiştirilmez.
        $codes = array_column($rows, 'nace_code');
        if (count($rows) !== 2144 || count(array_unique($codes)) !== count($codes)) {
            throw new RuntimeException('NACE listesi beklenen 2144 tekil kayıt değil: ' . count($rows));
        }
        foreach ($rows as $row) {
            if (!preg_match('/^\d{2}\.\d{2}(\.\d{2})?$/', $row['nace_code']) || !in_array($row['hazard_class'], self::SINIFLAR, true) || trim($row['activity_name']) === '') {
                throw new RuntimeException('Hatalı NACE satırı: ' . json_encode($row, JSON_UNESCAPED_UNICODE));
            }
        }

        $now = now();
        DB::transaction(function () use ($rows, $now) {
            DB::table('nace_hazard_classes')->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('nace_hazard_classes')->insert(array_map(fn (array $row) => [
                    'nace_code' => $row['nace_code'],
                    'activity_name' => trim($row['activity_name']),
                    'hazard_class' => $row['hazard_class'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }
        });

        $this->command?->info('NACE listesi yüklendi: ' . DB::table('nace_hazard_classes')->count() . ' kayıt.');
    }
}
