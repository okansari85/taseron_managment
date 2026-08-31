<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\District;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TurkeyLocationReferenceSeeder extends Seeder
{
    private const BASE_URL = 'https://api.turkiyeapi.dev/v2';

    public function run(): void
    {
        $provinces = $this->get('/provinces?fields=id,name&limit=100');
        $districts = $this->get('/districts?fields=id,name,provinceId&limit=1000');

        if (count($provinces) !== 81) {
            throw new RuntimeException('Türkiye il verisi beklenen 81 kayıt yerine '.count($provinces).' kayıt döndürdü.');
        }

        if (count($districts) < 900) {
            throw new RuntimeException('İlçe verisi eksik görünüyor: '.count($districts).' kayıt döndü.');
        }

        DB::transaction(function () use ($provinces, $districts): void {
            $now = now();

            City::upsert(
                collect($provinces)->map(fn (array $province) => [
                    'id' => $province['id'],
                    'name' => $province['name'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
                ['id'],
                ['name', 'updated_at']
            );

            District::upsert(
                collect($districts)->map(fn (array $district) => [
                    'id' => $district['id'],
                    'city_id' => $district['provinceId'],
                    'name' => $district['name'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
                ['id'],
                ['city_id', 'name', 'updated_at']
            );
        });

        $this->command?->info('Türkiye referans verileri senkronize edildi: '.count($provinces).' il, '.count($districts).' ilçe.');
    }

    private function get(string $path): array
    {
        $response = Http::acceptJson()
            ->timeout(30)
            ->retry(3, 1000)
            ->get(self::BASE_URL.$path);

        $response->throw();

        return $response->json('data') ?? [];
    }
}
