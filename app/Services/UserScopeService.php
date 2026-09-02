<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\UserScopeRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class UserScopeService
{
    private const TYPES = ['tenant', 'organization', 'location'];

    public function __construct(
        private UserScopeRepository $repository,
        private TenantContext $tenantContext,
    ) {
    }

    public function all(User $user): Collection
    {
        return $this->repository->all($user);
    }

    public function sync(User $user, array $scopes): Collection
    {
        $normalized = [];

        foreach ($scopes as $scope) {
            $type = (string) ($scope['scope_type'] ?? '');
            $id = (int) ($scope['scope_id'] ?? 0);

            if (!in_array($type, self::TYPES, true) || $id < 1) {
                throw ValidationException::withMessages(['scopes' => 'Geçersiz scope tanımı.']);
            }

            $this->assertBelongsToTenant($type, $id);
            $normalized[] = ['scope_type' => $type, 'scope_id' => $id];
        }

        $this->repository->sync($user, $normalized);

        return $this->all($user);
    }

    public function attach(User $user, string $type, int $id): Collection
    {
        if (!in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['scope_type' => 'Geçersiz scope tipi.']);
        }

        $this->assertBelongsToTenant($type, $id);
        $this->repository->attach($user, $type, $id);

        return $this->all($user);
    }

    public function detach(User $user, string $type, int $id): Collection
    {
        $this->repository->detach($user, $type, $id);
        return $this->all($user);
    }

    private function assertBelongsToTenant(string $type, int $id): void
    {
        $tenantId = $this->tenantContext->id();

        $exists = match ($type) {
            'tenant' => Tenant::query()->whereKey($id)->exists(),
            'organization' => Organization::query()->whereKey($id)->exists(),
            'location' => Location::query()->whereKey($id)->exists(),
        };

        if (!$exists) {
            throw ValidationException::withMessages(['scope_id' => 'Scope mevcut tenant kapsamında değil.']);
        }

        if ($type === 'tenant' && $id !== $tenantId) {
            throw ValidationException::withMessages(['scope_id' => 'Scope mevcut tenant kapsamında değil.']);
        }
    }
}
