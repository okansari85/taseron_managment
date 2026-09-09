<?php

namespace App\Services\Matching;

use App\Models\LocationBusinessEntity;

// 1. Kod → Kesin eşleşme
// 2. Tip/kategoriye özgü profil (marka+model+.. veya tip+kapasite+konum) → Aday eşleşme
// 3. Aday yoksa → Yeni ekipman adayı; aday >1 ise → Belirsiz eşleşme (kullanıcı onayı)
//
// Bu sınıf HANGİ profilin kullanılacağını bilmez — çağıran taraf (YSC veya
// Fire Suppression controller) kendi MatchingProfileInterface implementasyonunu
// enjekte eder. Böylece yarın yeni bir ekipman tipi/domain eklenince bu motor
// hiç değişmez, sadece yeni bir profil yazılır.
class MatchingEngine
{
    public function match(MatchingProfileInterface $profile, LocationBusinessEntity $branch, array $parsedEquipment): array
    {
        $code = $parsedEquipment['code'] ?? null;
        $exact = $profile->findExact($branch, $code);

        if ($exact) {
            return [
                'status' => 'exact',
                'matched_id' => $exact->id,
                'candidate_ids' => [],
            ];
        }

        $candidates = $profile->findCandidates($branch, $parsedEquipment);

        if ($candidates->isEmpty()) {
            return [
                'status' => 'new',
                'matched_id' => null,
                'candidate_ids' => [],
            ];
        }

        return [
            'status' => $candidates->count() === 1 ? 'candidate_single' : 'candidate_multiple',
            'matched_id' => null,
            'candidate_ids' => $candidates->pluck('id')->values()->all(),
        ];
    }
}
