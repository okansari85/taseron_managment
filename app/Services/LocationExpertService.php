<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Location;
use App\Models\User;
use App\Repositories\LocationExpertRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class LocationExpertService
{
    public function __construct(
        private LocationExpertRepository $repository,
        private TenantContext $tenantContext,
    ) {
    }

    public function all(Location $location): Collection
    {
        $this->assertLocationTenant($location);
        return $this->repository->all($location);
    }

    public function attach(Location $location, User $user): Collection
    {
        $this->assertLocationTenant($location);
        $this->repository->attach($location, $user);
        return $this->repository->all($location);
    }

    public function detach(Location $location, User $user): Collection
    {
        $this->assertLocationTenant($location);
        $this->repository->detach($location, $user);
        return $this->repository->all($location);
    }

    private function assertLocationTenant(Location $location): void
    {
        if ($location->tenant_id !== $this->tenantContext->id()) {
            throw ValidationException::withMessages([
                'location' => 'Lokasyon mevcut tenant kapsamında değil.',
            ]);
        }
    }
}
