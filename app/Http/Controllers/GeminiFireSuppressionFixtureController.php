<?php

namespace App\Http\Controllers;

use App\Services\Ai\FireSuppressionAiReportAnalyzer;
use App\Services\Ai\PdfTextExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GeminiFireSuppressionFixtureController extends Controller
{
    /**
     * Test-only semantic extraction endpoint.
     *
     * This endpoint intentionally does not dispatch the V12 analysis job.
     * Gemini is called once and its model JSON is stored as a reusable fixture
     * for later V12 tests.
     */
    public function analyze(
        Request $request,
        PdfTextExtractor $extractor,
        FireSuppressionAiReportAnalyzer $analyzer
    ): JsonResponse {
        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'max:51200'],
        ]);

        $file = $request->file('file');
        $pages = $extractor->extractPages($file);
        $semantic = $analyzer->analyze($pages);

        $fixtureId = (string) Str::uuid();
        $fixture = [
            'fixture_id' => $fixtureId,
            'provider' => 'gemini',
            'model' => config('services.gemini.text_model'),
            'original_file_name' => $file->getClientOriginalName(),
            'created_at' => now()->toIso8601String(),
            'semantic' => $semantic,
        ];

        Storage::disk('local')->put(
            "fire-suppression-gemini-fixtures/{$fixtureId}.json",
            json_encode($fixture, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );

        return response()->json(['data' => $fixture]);
    }
}
