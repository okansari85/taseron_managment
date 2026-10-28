<?php

namespace App\Observers;

use App\Models\PkInspection;
use Illuminate\Support\Facades\DB;

/**
 * Kontrol kaydı eklenince / değişince / silinince ekipmanın son kontrol özetini (pk_equipment.last_*, next_control_date)
 * yeniden hesaplar. Son kontrol PkEquipment::latestInspection ile aynı: kontrol tarihi en yeni, aynı günse en son girilen.
 */
class PkInspectionObserver
{
    public function saved(PkInspection $inspection): void
    {
        self::refresh($inspection->pk_equipment_id);
        // Kayıt başka ekipmana taşındıysa (tüp formunda eşleştirme düzeltmesi) eski ekipman da.
        if ($inspection->wasChanged('pk_equipment_id')) {
            self::refresh($inspection->getOriginal('pk_equipment_id'));
        }
    }

    public function deleted(PkInspection $inspection): void
    {
        self::refresh($inspection->pk_equipment_id);
    }

    public static function refresh(?int $equipmentId): void
    {
        if (!$equipmentId) {
            return;
        }
        // Kiracı kapsamı dışında da (komut, kuyruk) çalışır; ekipman kimliğiyle sınırlı.
        $latest = PkInspection::withoutGlobalScopes()->where('pk_equipment_id', $equipmentId)
            ->orderByDesc('control_date')->orderByDesc('id')->first();
        $findings = $latest ? self::findings($latest->findings) : [];

        DB::table('pk_equipment')->where('id', $equipmentId)->update([
            'last_inspection_id' => $latest?->id,
            'last_control_date' => $latest?->control_date?->format('Y-m-d'),
            'next_control_date' => $latest?->next_control_date?->format('Y-m-d'),
            'last_status' => $latest?->status,
            'last_findings_count' => count($findings),
            'last_findings' => $latest ? json_encode($findings, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    // Bulgular metin listesi (eski kayıtlarda {description: ...} nesnesi olabilir).
    private static function findings(mixed $findings): array
    {
        return array_values(array_filter(array_map(
            fn ($finding) => trim(is_array($finding) ? (string) ($finding['description'] ?? '') : (string) $finding),
            is_array($findings) ? $findings : []
        )));
    }
}
