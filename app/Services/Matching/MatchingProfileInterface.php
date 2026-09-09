<?php

namespace App\Services\Matching;

use App\Models\LocationBusinessEntity;
use Illuminate\Support\Collection;

// Her ekipman tipinin (YSC, Yangın Pompası, Hidrant, ...) kendi eşleştirme
// kurallarını tanımladığı sözleşme. "Matching Engine tek bir 'her ekipmanda
// aynı alanlara bak' mantığıyla çalışmayacak" — yeni bir ekipman tipi
// eklendiğinde SADECE yeni bir profil (veya paylaşımlı profile bir
// konfigürasyon satırı) eklenir, bu arayüz ve MatchingEngine hiç değişmez.
interface MatchingProfileInterface
{
    // Birincil/kesin kimlik alanına (ekipman kodu) göre TEK ve kesin eşleşme.
    public function findExact(LocationBusinessEntity $branch, ?string $code): ?object;

    // Kod bulunamazsa, o ekipman tipine özgü tanımlayıcı alan setine göre
    // (YSC: tip+kapasite+konum; Yangın Pompası: marka+model+seri+konum; ...)
    // aday(lar) arar. 0 sonuç → yeni ekipman adayı, 1 sonuç → tekil aday
    // (yine de "kesin" sayılmaz), 1'den fazla → belirsiz eşleşme.
    public function findCandidates(LocationBusinessEntity $branch, array $parsedEquipment): Collection;
}
