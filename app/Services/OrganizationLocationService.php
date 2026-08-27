<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Location;
use App\Models\Organization;
use App\Models\OrganizationLocation;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;

class OrganizationLocationService
{
    public function __construct(
        private TenantContext $tenantContext,
        private DatabaseManager $database,
    ) {
    }

    public function list(Organization $organization)
    {
        $this->assertTenantOrganization($organization);

        return OrganizationLocation::query()
            ->where('organization_id', $organization->id)
            ->with('location')
            ->latest('id')
            ->get();
    }

    public function attach(Organization $organization, Location $location): OrganizationLocation
    {
        $this->assertTenantOrganization($organization);
        $this->assertTenantLocation($location);

        return OrganizationLocation::firstOrCreate([
            'tenant_id' => $this->tenantContext->id(),
            'organization_id' => $organization->id,
            'location_id' => $location->id,
        ]);
    }

    public function detach(Organization $organization, Location $location): void
    {
        $this->assertTenantOrganization($organization);
        $this->assertTenantLocation($location);

        OrganizationLocation::query()
            ->where('tenant_id', $this->tenantContext->id())
            ->where('organization_id', $organization->id)
            ->where('location_id', $location->id)
            ->delete();
    }

    public function createForOrganization(Organization $organization, array $data): Location
    {
        $this->assertTenantOrganization($organization);

        return $this->database->transaction(function () use ($organization, $data) {
            $location = Location::create([
                'tenant_id' => $this->tenantContext->id(),
                'name' => $data['name'],
            ]);

            OrganizationLocation::create([
                'tenant_id' => $this->tenantContext->id(),
                'organization_id' => $organization->id,
                'location_id' => $location->id,
            ]);

            return $location;
        });
    }

    private function assertTenantOrganization(Organization $organization): void
    {
        if ($organization->tenant_id !== $this->tenantContext->id()) {
            throw ValidationException::withMessages([
                'organization' => 'Organizasyon mevcut tenant kapsamında değil.',
            ]);
        }
    }

    private function assertTenantLocation(Location $location): void
    {
        if ($location->tenant_id !== $this->tenantContext->id()) {
            throw ValidationException::withMessages([
                'location' => 'Lokasyon mevcut tenant kapsamında değil.',
            ]);
        }
    }
}
