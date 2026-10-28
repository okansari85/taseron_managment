<?php

namespace App\Http\Controllers;

use App\Services\Ai\PkTakip\PkReportFixtureStore;
use App\Services\Ai\PkTakip\PkSingleEquipmentReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Test sayfası denemesi: tek ekipman raporu kısa talimatla (tür seçilir). Sonuç diğer test analizleriyle aynı biçimde
 * pk-report-fixtures'a yazılır (Kayıtlı analizler listesinde görünür). Mevcut test ucu (PkReportAnalysisTestController) değişmez.
 */
class PkShortPromptTestController extends Controller
{
    public function store(Request $request, PkSingleEquipmentReader $reader, PkReportFixtureStore $store): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required_without:legacy_fixture_id', 'file', 'mimes:pdf', 'max:51200'],
            'legacy_fixture_id' => ['required_without:file', 'nullable', 'string'],
            'type' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/'],
        ]);

        set_time_limit(900);

        try {
            [$pdfPath, $originalName] = $request->hasFile('file')
                ? [$request->file('file')->getRealPath(), $request->file('file')->getClientOriginalName()]
                : $store->legacyPdf((string) $data['legacy_fixture_id']);
            $result = $reader->read($pdfPath, $data['type']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        $fixtureId = (string) Str::uuid();
        $storedPdf = PkReportFixtureStore::DIR . "/{$fixtureId}.pdf";
        Storage::disk('local')->put($storedPdf, file_get_contents($pdfPath));
        $fixture = [
            'fixture_id' => $fixtureId,
            'analyzer' => 'pk_report_short',
            'provider' => $result['provider'],
            'model' => $result['model'],
            'original_file_name' => $originalName,
            'created_at' => now()->toIso8601String(),
            'duration_s' => $result['duration_s'],
            'page_count' => $result['page_count'],
            'input' => 'text',
            'ocr_pdf_path' => null,
            'pdf_path' => $storedPdf,
            'context' => ['mode' => 'short', 'type' => $data['type']],
            'semantic' => $result['semantic'],
            // Tek ekipman raporunda değerler yapay zekadan gelir; tablo (Camelot) adımı yok.
            'tables' => null,
        ];
        Storage::disk('local')->put(PkReportFixtureStore::DIR . "/{$fixtureId}.json", json_encode($fixture, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        // Tablolar test sayfasındaki "tabloları yeniden oku" ile aynı yoldan; okunamazsa yapay zeka sonucu yine döner.
        try {
            $fixture = $store->rereadTables($fixtureId);
        } catch (RuntimeException) {
        }

        return response()->json($fixture, 201);
    }
}
