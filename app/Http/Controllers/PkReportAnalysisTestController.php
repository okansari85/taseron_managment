<?php

namespace App\Http\Controllers;

use App\Services\Ai\PkTakip\PkReportFixtureStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

// pktakip uzman paneli "Test" ekranı: yeni rapor algılamasını (kriterler hariç) PDF üzerinde çalıştırır,
// sonucu fixture olarak saklar ve listeler. Eski yangın raporu akışını kullanmaz/değiştirmez.
class PkReportAnalysisTestController extends Controller
{
    public function __construct(private PkReportFixtureStore $store)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'fixtures' => $this->store->list(),
            'legacy_fixtures' => $this->store->legacyList(),
        ]);
    }

    public function show(string $fixtureId): JsonResponse
    {
        try {
            return response()->json($this->store->get($fixtureId));
        } catch (RuntimeException $exception) {
            abort(404, $exception->getMessage());
        }
    }

    // Kayıtlı analizin ekipman tablolarını (Camelot) Gemini'ye tekrar gitmeden yeniden okur.
    public function rereadTables(string $fixtureId): JsonResponse
    {
        set_time_limit(600);
        try {
            return response()->json($this->store->rereadTables($fixtureId));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['fixture' => $exception->getMessage()]);
        }
    }

    // Senkron çalışır (kuyruk worker'ı gerektirmez); Gemini çağrısı 1-3 dakika sürebilir.
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required_without:legacy_fixture_id', 'file', 'mimes:pdf', 'max:51200'],
            'legacy_fixture_id' => ['required_without:file', 'nullable', 'string'],
        ]);

        set_time_limit(600);

        try {
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $fixture = $this->store->analyze($file->getRealPath(), $file->getClientOriginalName());
            } else {
                [$pdfPath, $originalName] = $this->store->legacyPdf((string) $request->input('legacy_fixture_id'));
                $fixture = $this->store->analyze($pdfPath, $originalName);
            }
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        return response()->json($fixture, 201);
    }
}
