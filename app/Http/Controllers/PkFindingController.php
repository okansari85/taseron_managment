<?php

namespace App\Http\Controllers;

use App\Models\PkFinding;
use App\Models\PkFindingEvent;
use App\Services\PkFindingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

// pktakip Bulgu Takip (2026-11-12). Kiracı filtresi PkFinding'in global kapsamında; rota bağlaması başka hesabın bulgusunu bulmaz.
class PkFindingController extends Controller
{
    public function __construct(private PkFindingService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->service->list($request->validate([
            'location_id' => ['nullable', 'integer'],
            'location_ids' => ['nullable', 'array', 'max:500'],
            'location_ids.*' => ['integer'],
            'workplace_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in([...PkFinding::STATUSES, 'suresi_gecen', 'yaklasan'])],
            'responsible_user_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ])));
    }

    public function count(Request $request): JsonResponse
    {
        return response()->json(['open' => $this->service->openCount($request->validate([
                'location_ids' => ['nullable', 'array', 'max:500'],
                'location_ids.*' => ['integer'],
            ]))]);
    }

    public function responsibles(): JsonResponse
    {
        return response()->json($this->service->responsibles());
    }

    public function rechecks(Request $request): JsonResponse
    {
        return response()->json($this->service->rechecks($request->validate([
            'location_id' => ['nullable', 'integer'],
            'location_ids' => ['nullable', 'array', 'max:500'],
            'location_ids.*' => ['integer'],
            'workplace_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ])));
    }

    public function show(PkFinding $pkFinding): JsonResponse
    {
        return response()->json($this->service->show($pkFinding));
    }

    public function update(Request $request, PkFinding $pkFinding): JsonResponse
    {
        $data = $request->validate([
            'work' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'responsible_user_id' => ['sometimes', 'nullable', 'integer'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'recheck_date' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', Rule::in(PkFinding::STATUSES)],
        ]);
        $this->service->update($pkFinding, $data, $request->user());

        return response()->json($this->service->show($pkFinding->refresh()));
    }

    public function note(Request $request, PkFinding $pkFinding): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:5000']]);
        $this->service->addNote($pkFinding, $data['note'], $request->user());

        return response()->json($this->service->show($pkFinding), 201);
    }

    public function storeFile(Request $request, PkFinding $pkFinding): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,webp,heic,doc,docx,xls,xlsx'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $this->service->addFile($pkFinding, $data['file'], $data['note'] ?? null, $request->user());

        return response()->json($this->service->show($pkFinding), 201);
    }

    public function file(PkFinding $pkFinding, PkFindingEvent $event): BinaryFileResponse
    {
        [$path, $name] = $this->service->file($pkFinding, $event);

        return response()->file($path, ['Content-Disposition' => 'inline; filename="' . addslashes($name) . '"']);
    }

    public function close(Request $request, PkFinding $pkFinding): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:5000']]);
        $this->service->close($pkFinding, $data['note'] ?? null, $request->user());

        return response()->json($this->service->show($pkFinding->refresh()));
    }

    public function reopen(Request $request, PkFinding $pkFinding): JsonResponse
    {
        $this->service->reopen($pkFinding, $request->user());

        return response()->json($this->service->show($pkFinding->refresh()));
    }

    public function rejectSuggestion(Request $request, PkFinding $pkFinding): JsonResponse
    {
        $this->service->rejectSuggestion($pkFinding, $request->user());

        return response()->json($this->service->show($pkFinding->refresh()));
    }
}
