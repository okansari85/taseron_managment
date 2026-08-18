<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Organization;
use App\Repositories\Contracts\OrganizationLocationRepositoryInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrganizationLocationService
{
    public function __construct(
        private OrganizationLocationRepositoryInterface $repository
    ) {
    }

    public function attach(
        Organization $organization,
        Location $location
    ): void {
        $this->ensureSameTenant($organization, $location);

        DB::transaction(function () use ($organization, $location) {
            $this->repository->attach(
                $organization,
                $location
            );
        });
    }

    public function detach(
        Organization $organization,
        Location $location
    ): void {
        $this->ensureSameTenant($organization, $location);

        DB::transaction(function () use ($organization, $location) {
            $this->repository->detach(
                $organization,
                $location
            );
        });
    }

    public function sync(
        Organization $organization,
        array $locationIds
    ): void {
        $locations = Location::query()
            ->whereIn('id', $locationIds)
            ->get();

        if ($locations->count() !== count(array_unique($locationIds))) {
            throw new RuntimeException(
                'Lokasyonlardan biri veya birkaçı bulunamadı.'
            );
        }

        foreach ($locations as $location) {
            $this->ensureSameTenant(
                $organization,
                $location
            );
        }

        DB::transaction(function () use (
            $organization,
            $locationIds
        ) {
            $this->repository->sync(
                $organization,
                $locationIds
            );
        });
    }

    private function ensureSameTenant(
        Organization $organization,
        Location $location
    ): void {
        if ($organization->tenant_id !== $location->tenant_id) {
            throw new RuntimeException(
                'Organization ve Location aynı tenant içerisinde olmalıdır.'
            );
        }
    }
}
