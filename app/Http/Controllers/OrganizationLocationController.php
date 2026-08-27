<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Organization;
use App\Services\OrganizationLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationLocationController extends Controller
{
    public function __construct(
        private OrganizationLocationService $service
    ) {
    }

    public function index(Organization $organization): JsonResponse
    {
        return response()->json(
            $this->service->list($organization)
        );
    }

    public function attach(Organization $organization, Location $location): JsonResponse
    {
        return response()->json(
            $this->service->attach($organization, $location),
            201
        );
    }

    public function detach(Organization $organization, Location $location): JsonResponse
    {
        $this->service->detach($organization, $location);

        return response()->json([
            'message' => 'Lokasyon organizasyondan ayrıldı.',
        ]);
    }

    public function store(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $location = $this->service->createForOrganization($organization, $data);

        return response()->json($location, 201);
    }
}
