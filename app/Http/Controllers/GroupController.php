<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGroupRequest;
use App\Services\GroupService;
use Illuminate\Http\JsonResponse;

class GroupController extends Controller
{
    public function __construct(
        private GroupService $service
    ) {
    }

    public function store(StoreGroupRequest $request): JsonResponse
    {
        $group = $this->service->create(
            $request->validated()
        );

        return response()->json(
            $group,
            201
        );
    }
}
