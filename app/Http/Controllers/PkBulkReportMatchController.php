<?php

namespace App\Http\Controllers;

use App\Models\PkBulkReport;
use App\Models\PkBulkReportPendingRow;
use App\Models\PkInspection;
use App\Services\PkBulkReportMatchService;
use App\Services\PkBulkReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// Tüp kontrol formu eşleştirme: eşleşmeyen satırlar eşleştirme bekler (kayıt ucu), sonradan mevcut tüpe bağlanır, yeni tüp
// olarak eklenir ya da yok sayılır; formla açılmış tüp mevcut tüpe taşınır. Formun kendi uçları (PkBulkReportController) aynı.
class PkBulkReportMatchController extends Controller
{
    public function __construct(private PkBulkReportMatchService $service, private PkBulkReportService $forms)
    {
    }

    // Kayıt (PkBulkReportController::store'un kuralları; satırlarda mevcut tüp zorunlu, eşleşmeyenler pending_rows).
    public function store(Request $request): JsonResponse
    {
        foreach (['rows', 'pending_rows', 'systems', 'findings'] as $key) {
            if (is_string($value = $request->input($key))) {
                $request->merge([$key => json_decode($value, true) ?? []]);
            }
        }
        set_time_limit(600);
        $row = fn (string $prefix) => [
            "{$prefix}.*.status" => ['nullable', Rule::in(['uygun', 'uygun_degil', 'belirtilmemis'])],
            "{$prefix}.*.code" => ['nullable', 'string', 'max:100'],
            "{$prefix}.*.place" => ['nullable', 'string', 'max:255'],
            "{$prefix}.*.variant" => ['nullable', 'string', 'max:50'],
            "{$prefix}.*.brand" => ['nullable', 'string', 'max:100'],
            "{$prefix}.*.model" => ['nullable', 'string', 'max:100'],
            "{$prefix}.*.serial_no" => ['nullable', 'string', 'max:100'],
            "{$prefix}.*.properties" => ['nullable', 'array', 'max:100'],
            "{$prefix}.*.findings" => ['nullable', 'array', 'max:200'],
            "{$prefix}.*.findings.*" => ['nullable', 'string', 'max:5000'],
        ];
        $data = $request->validate([
            'report' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:51200'],
            'analysis_id' => ['nullable', 'uuid'],
            'location_id' => ['required', 'integer'],
            'status' => ['nullable', Rule::in(['uygun', 'uygun_degil'])],
            'control_date' => ['required', 'date'],
            'next_control_date' => ['nullable', 'date', 'after_or_equal:control_date'],
            'report_no' => ['nullable', 'string', 'max:100'],
            'form_scope' => ['nullable', Rule::in(['full', 'partial'])],
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
            'rows' => ['nullable', 'array', 'max:1000'],
            'rows.*.equipment_id' => ['required', 'integer'],
            'rows.*.update_place' => ['nullable', 'boolean'],
            ...$row('rows'),
            'pending_rows' => ['nullable', 'array', 'max:1000'],
            'pending_rows.*.type_text' => ['nullable', 'string', 'max:100'],
            'pending_rows.*.capacity' => ['nullable', 'string', 'max:50'],
            'pending_rows.*.fill_date' => ['nullable', 'string', 'max:50'],
            ...$row('pending_rows'),
        ], [
            'report.required' => 'Kontrol formunun PDF\'i yüklenmelidir.',
            'control_date.required' => 'Kontrol tarihi zorunludur.',
            'next_control_date.after_or_equal' => 'Gelecek kontrol tarihi kontrol tarihinden önce olamaz.',
        ]);

        $report = $this->service->save($data, $request->file('report'), $request->user());

        return response()->json($this->forms->show($report), 201);
    }

    public function matching(PkBulkReport $pkBulkReport): JsonResponse
    {
        return response()->json($this->service->matching($pkBulkReport));
    }

    public function pendingCounts(Request $request): JsonResponse
    {
        $data = $request->validate(['location_id' => ['required', 'integer']]);

        return response()->json($this->service->pendingCounts((int) $data['location_id']));
    }

    // "Eşleştirme bekliyor" sekmesi: pasife alınacaklar (sol) ve yeni eklenecekler (sağ).
    public function board(Request $request): JsonResponse
    {
        $data = $request->validate(['location_id' => ['required', 'integer']]);

        return response()->json($this->service->board((int) $data['location_id']));
    }

    // Formların türü (tüm envanter / ek form) ve değiştirme.
    public function scopes(Request $request): JsonResponse
    {
        $data = $request->validate(['location_id' => ['required', 'integer']]);

        return response()->json($this->service->scopes((int) $data['location_id']));
    }

    public function setScope(Request $request, PkBulkReport $pkBulkReport): JsonResponse
    {
        $data = $request->validate(['scope' => ['required', Rule::in(['full', 'partial'])]]);
        $this->service->setScope($pkBulkReport, $data['scope']);

        return response()->json(['scope' => $data['scope']]);
    }

    // Sekmeden eşleştirme: sağdaki satır → soldaki tüp (tüp yeni rapordaki adı alır).
    public function boardMatch(Request $request): JsonResponse
    {
        $data = $request->validate(['row_id' => ['required', 'integer'], 'equipment_id' => ['required', 'integer']]);
        $this->service->matchBoardRow((int) $data['row_id'], (int) $data['equipment_id'], $request->user());

        return response()->json(['matched' => true]);
    }

    // Sekmeden yeni tüp: seçilen satırlar Ekipmanlar'a eklenir.
    public function boardAdd(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:1000'], 'ids.*' => ['integer']]);
        set_time_limit(600);

        return response()->json(['added' => $this->service->addBoardRows($data['ids'], $request->user())]);
    }

    // Bekleyen satır → mevcut tüp.
    public function match(Request $request, PkBulkReport $pkBulkReport, PkBulkReportPendingRow $pendingRow): JsonResponse
    {
        $data = $request->validate(['equipment_id' => ['required', 'integer'], 'update_place' => ['nullable', 'boolean']]);
        $this->service->matchRow($pkBulkReport, $pendingRow, (int) $data['equipment_id'], (bool) ($data['update_place'] ?? false), $request->user());

        return response()->json($this->service->matching($pkBulkReport));
    }

    // Bekleyen satırlar → yeni tüp (tek ya da seçilenler).
    public function add(Request $request, PkBulkReport $pkBulkReport): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:1000'], 'ids.*' => ['integer']]);
        set_time_limit(600);
        $this->service->addRows($pkBulkReport, $data['ids'], $request->user());

        return response()->json($this->service->matching($pkBulkReport));
    }

    // Bekleyen satırlar yok sayılır.
    public function skip(Request $request, PkBulkReport $pkBulkReport): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:1000'], 'ids.*' => ['integer']]);
        $this->service->skipRows($pkBulkReport, $data['ids']);

        return response()->json($this->service->matching($pkBulkReport));
    }

    // Formla açılmış tüp → mevcut tüp (kontrol kaydı taşınır, formla açılan kayıt silinir).
    public function move(Request $request, PkBulkReport $pkBulkReport, PkInspection $pkInspection): JsonResponse
    {
        $data = $request->validate(['equipment_id' => ['required', 'integer'], 'update_place' => ['nullable', 'boolean']]);
        $this->service->moveInspection($pkBulkReport, $pkInspection, (int) $data['equipment_id'], (bool) ($data['update_place'] ?? false), $request->user());

        return response()->json($this->service->matching($pkBulkReport));
    }
}
