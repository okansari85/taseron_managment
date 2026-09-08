<?php

namespace App\Http\Controllers;

use App\Models\LocationBusinessEntity;
use App\Models\User;
use App\Services\LocationExpertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationExpertController extends Controller
{
    public function __construct(private LocationExpertService $service)
    {
    }

    public function index(LocationBusinessEntity $locationBusinessEntity): JsonResponse
    {
        return response()->json($this->service->all($locationBusinessEntity));
    }

    public function attach(Request $request, LocationBusinessEntity $locationBusinessEntity): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        $user = User::query()->findOrFail($data['user_id']);

        return response()->json($this->service->attach($locationBusinessEntity, $user), 201);
    }

    public function detach(LocationBusinessEntity $locationBusinessEntity, User $user): JsonResponse
    {
        return response()->json($this->service->detach($locationBusinessEntity, $user));
    }

    public function forUser(User $user): JsonResponse
    {
        return response()->json($this->service->forUser($user));
    }
}
