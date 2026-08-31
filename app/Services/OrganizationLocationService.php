<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Location;
use App\Models\Organization;
use App\Models\OrganizationLocation;
use App\Repositories\Contracts\OrganizationLocationRepositoryInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;

class OrganizationLocationService
{
    public function __construct(
        private TenantContext $tenantContext,
        private DatabaseManager $database,
        private OrganizationLocationRepositoryInterface $repository,
    ) {
    }

    public function list(Organization $organization)
    {
        $this->assertTenantOrganization($organization);
        return $this->repository->list($organization);
    }

    public function attach(Organization $organization, Location $location): OrganizationLocation
    {
        $this->assertTenantOrganization($organization);
        $this->assertTenantLocation($location);
        return $this->repository->attach($organization, $location);
    }

    public function detach(Organization $organization, Location $location): void
    {
        $this->assertTenantOrganization($organization);
        $this->assertTenantLocation($location);
        $this->repository->detach($organization, $location);
    }

    public function sync(Organization $organization, array $locationIds): array
    {
        $this->assertTenantOrganization($organization);
        $locationIds = array_values(array_unique(array_map('intval', $locationIds)));

        $locations = Location::query()->whereIn('id', $locationIds)->get();
        if ($locations->count() !== count($locationIds)) {
            throw ValidationException::withMessages([
                'location_ids' => 'Seçilen lokasyonlardan biri mevcut tenant kapsamında bulunamadı.',
            ]);
        }

        return $this->database->transaction(function () use ($organization, $locations) {
            foreach ($locations as $location) {
                $this->assertTenantLocation($location);
                OrganizationLocation::query()
                    ->where('location_id', $location->id)
                    ->delete();
                $this->repository->attach($organization, $location);
            }

            return $locations->load('tenant')->values()->all();
        });
    }

    public function createForOrganization(Organization $organization, array $data): Location
    {
        $this->assertTenantOrganization($organization);
        return $this->database->transaction(function () use ($organization, $data) {
            $location = Location::create([
                'tenant_id' => $this->tenantContext->id(),
                'name' => $data['name'],
            ]);
            $this->repository->attach($organization, $location);
            return $location;
        });
    }

    private function assertTenantOrganization(Organization $organization): void
    {
        if ($organization->tenant_id !== $this->tenantContext->id()) {
            throw ValidationException::withMessages(['organization' => 'Organizasyon mevcut tenant kapsamında değil.']);
        }
    }

    private function assertTenantLocation(Location $location): void
    {
        if ($location->tenant_id !== $this->tenantContext->id()) {
            throw ValidationException::withMessages(['location' => 'Lokasyon mevcut tenant kapsamında değil.']);
        }
    }
}
