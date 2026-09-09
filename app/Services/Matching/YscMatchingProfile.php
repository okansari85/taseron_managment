<?php

namespace App\Services\Matching;

use App\Models\LocationBusinessEntity;
use App\Models\LocationEmergencyEquipment;
use Illuminate\Support\Collection;

// YSC eşleştirme profili — "YSC'nin kimliği cihaz kodu olmalı, seri no yok."
// Kesin eşleşme SADECE ekipman koduyla; aday arama tip+kapasite+konum ile —
// seri no hiçbir aşamada matching'e karışmaz (parser'da veri olarak
// kalmaya devam eder, sadece burada kullanılmaz).
class YscMatchingProfile implements MatchingProfileInterface
{
    public function findExact(LocationBusinessEntity $branch, ?string $code): ?object
    {
        if (! $code) {
            return null;
        }

        return LocationEmergencyEquipment::query()
            ->where('location_business_entity_id', $branch->id)
            ->where('code', $code)
            ->first();
    }

    public function findCandidates(LocationBusinessEntity $branch, array $parsedEquipment): Collection
    {
        $type = $parsedEquipment['equipment_type'] ?? null;
        $capacity = $parsedEquipment['capacity'] ?? null;
        $location = $parsedEquipment['location_note'] ?? null;

        if (! $type && ! $capacity && ! $location) {
            return new Collection();
        }

        return LocationEmergencyEquipment::query()
            ->where('location_business_entity_id', $branch->id)
            ->whereHas('equipmentType', function ($query) use ($type, $capacity) {
                if ($type) {
                    $query->where('tip', $type);
                }
                if ($capacity) {
                    $query->where('capacity_kg', $capacity);
                }
            })
            ->when($location, fn ($query) => $query->where('location_note', $location))
            ->with('equipmentType')
            ->get();
    }
}
