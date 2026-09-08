<?php

namespace App\Http\Controllers;

use App\Services\WorkspaceContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkspaceContextController extends Controller
{
    public function __construct(
        private WorkspaceContextService $workspaceContext,
    ) {
    }

    public function bootstrap(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->workspaceContext->bootstrap($request->user()),
        ]);
    }

    public function organizations(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->workspaceContext->organizationOptions($request->user()),
        ]);
    }

    public function locations(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'integer', 'min:1'],
            'kind' => ['sometimes', Rule::in(['organization', 'brand'])],
        ]);

        try {
            $result = $this->workspaceContext->locationOptions(
                $request->user(),
                (int) $data['organization_id'],
                $data['kind'] ?? 'organization'
            );
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 403);
        }

        return response()->json(['data' => $result]);
    }

    public function operationalAreas(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json([
            'data' => $this->workspaceContext->operationalAreaOptions($request->user(), (int) $data['location_id']),
        ]);
    }
}
