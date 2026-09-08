<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\LocationBusinessEntity;
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

    public function all(LocationBusinessEntity $locationBusinessEntity): Collection
    {
        $this->assertEntityTenant($locationBusinessEntity);
        return $this->repository->all($locationBusinessEntity);
    }

    public function attach(LocationBusinessEntity $locationBusinessEntity, User $user): Collection
    {
        $this->assertEntityTenant($locationBusinessEntity);
        $this->repository->attach($locationBusinessEntity, $user);
        return $this->repository->all($locationBusinessEntity);
    }

    public function detach(LocationBusinessEntity $locationBusinessEntity, User $user): Collection
    {
        $this->assertEntityTenant($locationBusinessEntity);
        $this->repository->detach($locationBusinessEntity, $user);
        return $this->repository->all($locationBusinessEntity);
    }

    public function forUser(User $user): Collection
    {
        return $this->repository->forUser($user, $this->tenantContext->id());
    }

    private function assertEntityTenant(LocationBusinessEntity $locationBusinessEntity): void
    {
        $tenantId = $locationBusinessEntity->location?->tenant_id
            ?? $locationBusinessEntity->location()->value('tenant_id');

        if ($tenantId !== $this->tenantContext->id()) {
            throw ValidationException::withMessages([
                'location_business_entity' => 'Bu kayıt mevcut tenant kapsamında değil.',
            ]);
        }
    }
}
