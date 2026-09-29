<?php

namespace App\Http\Controllers;

use App\Services\PkReportAnalysisHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

// pktakip Ayarlar → Analiz geçmişi: hesaptaki tüm yapay zeka rapor okumaları (salt okunur; satır silinmez).
class PkReportAnalysisHistoryController extends Controller
{
    public function __construct(private PkReportAnalysisHistory $history)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->history->list($request->validate([
            'location_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'kind' => ['nullable', Rule::in(['equipment', 'equipment_new', 'bulk', 'installation'])],
            'status' => ['nullable', Rule::in(['read', 'saved', 'failed'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
        ])));
    }

    public function show(string $analysis): JsonResponse
    {
        return response()->json($this->history->show($analysis));
    }

    public function file(string $analysis): BinaryFileResponse
    {
        [$path, $name] = $this->history->file($analysis);

        return response()->file($path, ['Content-Disposition' => 'inline; filename="' . addslashes($name) . '"']);
    }
}
