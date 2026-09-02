<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\User;
use App\Services\LocationExpertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationExpertController extends Controller
{
    public function __construct(private LocationExpertService $service)
    {
    }

    public function index(Location $location): JsonResponse
    {
        return response()->json($this->service->all($location));
    }

    public function attach(Request $request, Location $location): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        $user = User::query()->findOrFail($data['user_id']);

        return response()->json($this->service->attach($location, $user), 201);
    }

    public function detach(Location $location, User $user): JsonResponse
    {
        return response()->json($this->service->detach($location, $user));
    }
}
