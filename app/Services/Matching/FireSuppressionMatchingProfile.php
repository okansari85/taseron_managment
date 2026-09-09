<?php

namespace App\Services\Matching;

use App\Models\FireSuppressionInventoryItem;
use App\Models\LocationBusinessEntity;
use Illuminate\Support\Collection;

// Yangın Söndürme Sistemleri eşleştirme profili — TEK model
// (FireSuppressionInventoryItem) tüm kategorileri paylaştığı için tek
// sınıf, ama aday aramada HANGİ alanların kullanılacağı kategoriye göre
// değişir ("matching motorunu tek bir mantıkla yapmayalım"):
//   Yangın Pompası / Gazlı Söndürme → marka + model + seri no + konum
//   Yangın Dolabı / Hidrant / diğerleri → marka + model + konum (seri no yok)
// Yarın yeni bir kategori eklendiğinde CANDIDATE_FIELDS'e bir satır eklemek
// yeterlidir, algoritma değişmez.
class FireSuppressionMatchingProfile implements MatchingProfileInterface
{
    private const CANDIDATE_FIELDS = [
        'yangin_pompasi' => ['brand', 'model', 'serial_no', 'location_note'],
        'gazli_sondurme' => ['brand', 'model', 'serial_no', 'location_note'],
        'yangin_dolabi' => ['brand', 'model', 'location_note'],
        'hidrant' => ['brand', 'model', 'location_note'],
        'sprinkler' => ['brand', 'model', 'location_note'],
        'su_deposu' => ['brand', 'model', 'location_note'],
        'diger' => ['brand', 'model', 'location_note'],
    ];

    public function findExact(LocationBusinessEntity $branch, ?string $code): ?object
    {
        if (! $code) {
            return null;
        }

        return FireSuppressionInventoryItem::query()
            ->where('location_business_entity_id', $branch->id)
            ->where('code', $code)
            ->first();
    }

    public function findCandidates(LocationBusinessEntity $branch, array $parsedEquipment): Collection
    {
        $category = $parsedEquipment['category'] ?? null;
        $fields = self::CANDIDATE_FIELDS[$category] ?? ['brand', 'model', 'location_note'];

        $query = FireSuppressionInventoryItem::query()
            ->where('location_business_entity_id', $branch->id);

        if ($category) {
            $query->where('category', $category);
        }

        $hasAnyCriteria = false;

        foreach ($fields as $field) {
            $value = $parsedEquipment[$field] ?? null;
            if ($value) {
                $query->where($field, $value);
                $hasAnyCriteria = true;
            }
        }

        if (! $hasAnyCriteria) {
            return new Collection();
        }

        return $query->get();
    }
}
