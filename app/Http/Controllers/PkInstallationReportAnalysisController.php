<?php

namespace App\Http\Controllers;

use App\Models\PkInstallation;
use App\Models\PkInstallationReport;
use App\Services\PkInstallationService;
use Illuminate\Http\JsonResponse;

// pktakip Tesisatlar, Kontrol geçmişi (bütün tesisatlar): bir kontrol raporunun yapay zeka okuması (ham bilgiler); satır açılınca
// yüklenir. Yalnızca okur; mevcut tesisat uçları (PkInstallationController) değişmez.
class PkInstallationReportAnalysisController extends Controller
{
    public function __construct(private PkInstallationService $service)
    {
    }

    public function show(PkInstallation $pkInstallation, PkInstallationReport $pkInstallationReport): JsonResponse
    {
        return response()->json(['analysis' => $this->service->reportAnalysis($pkInstallation, $pkInstallationReport)]);
    }
}
