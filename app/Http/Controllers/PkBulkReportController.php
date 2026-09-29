<?php

namespace App\Http\Controllers;

use App\Models\PkBulkReport;
use App\Services\PkBulkReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

// Tüp kontrol formları (Ekipmanlar → Yangın Söndürme Cihazı → Kontrol Formları): liste, detay, kayıt, rapor dosyası, silme.
// Okuma "Rapordan Ekipman Tanımla" ile aynı uçtan (pk-equipment/report-analysis) yapılır.
class PkBulkReportController extends Controller
{
    public function __construct(private PkBulkReportService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->service->list($request->validate([
            'location_id' => ['required', 'integer'],
            'workplace_id' => ['nullable', 'integer'],
        ])));
    }

    public function show(PkBulkReport $pkBulkReport): JsonResponse
    {
        return response()->json($this->service->show($pkBulkReport));
    }

    // Multipart; yüzlerce tüpte PHP alan sınırına (max_input_vars) takılmamak için liste alanları JSON metni olarak gelir.
    public function store(Request $request): JsonResponse
    {
        foreach (['rows', 'systems', 'findings'] as $key) {
            if (is_string($value = $request->input($key))) {
                $request->merge([$key => json_decode($value, true) ?? []]);
            }
        }
        set_time_limit(600);
        $data = $request->validate([
            'report' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:51200'],
            'analysis_id' => ['nullable', 'uuid'],
            // Tüpler lokasyon geneline kayıtlıdır; işyeri seçilmez.
            'location_id' => ['required', 'integer'],
            'status' => ['nullable', Rule::in(['uygun', 'uygun_degil'])],
            'control_date' => ['required', 'date'],
            'next_control_date' => ['nullable', 'date', 'after_or_equal:control_date'],
            'report_no' => ['nullable', 'string', 'max:100'],
            'inspection_body' => ['nullable', 'string', 'max:255'],
            'conclusion' => ['nullable', 'string'],
            'systems' => ['nullable', 'array', 'max:50'],
            'systems.*.name' => ['required', 'string', 'max:255'],
            'systems.*.status' => ['nullable', Rule::in(['uygun', 'uygun_degil'])],
            'systems.*.findings' => ['nullable', 'array', 'max:500'],
            'systems.*.findings.*' => ['nullable', 'string', 'max:5000'],
            'systems.*.equipment_count' => ['nullable', 'integer', 'min:0'],
            'findings' => ['nullable', 'array', 'max:500'],
            'findings.*' => ['nullable', 'string', 'max:5000'],
            'rows' => ['required', 'array', 'min:1', 'max:1000'],
            'rows.*.equipment_id' => ['nullable', 'integer'],
            'rows.*.status' => ['nullable', Rule::in(['uygun', 'uygun_degil', 'belirtilmemis'])],
            'rows.*.code' => ['nullable', 'string', 'max:100'],
            'rows.*.place' => ['nullable', 'string', 'max:255'],
            'rows.*.variant' => ['nullable', 'string', 'max:50'],
            'rows.*.brand' => ['nullable', 'string', 'max:100'],
            'rows.*.model' => ['nullable', 'string', 'max:100'],
            'rows.*.serial_no' => ['nullable', 'string', 'max:100'],
            'rows.*.properties' => ['nullable', 'array', 'max:100'],
            'rows.*.findings' => ['nullable', 'array', 'max:200'],
            'rows.*.findings.*' => ['nullable', 'string', 'max:5000'],
        ], [
            'report.required' => 'Kontrol formunun PDF\'i yüklenmelidir.',
            'control_date.required' => 'Kontrol tarihi zorunludur.',
            'next_control_date.after_or_equal' => 'Gelecek kontrol tarihi kontrol tarihinden önce olamaz.',
            'rows.required' => 'Kaydedilecek tüp yok.',
        ]);

        $report = $this->service->save($data, $request->file('report'), $request->user());

        return response()->json($this->service->show($report), 201);
    }

    public function file(PkBulkReport $pkBulkReport): BinaryFileResponse
    {
        [$path, $name] = $this->service->reportFile($pkBulkReport);

        return response()->file($path, ['Content-Disposition' => 'inline; filename="' . addslashes($name) . '"']);
    }

    public function destroy(PkBulkReport $pkBulkReport): JsonResponse
    {
        $this->service->delete($pkBulkReport);

        return response()->json(['deleted' => true]);
    }
}
