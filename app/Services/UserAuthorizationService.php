<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\UserAuthorizationRepository;
use Spatie\Permission\Models\Role;

class UserAuthorizationService
{
    public function __construct(private UserAuthorizationRepository $repository)
    {
    }

    public function all()
    {
        return $this->repository->all();
    }

    public function find(int $id): User
    {
        return $this->repository->find($id);
    }

    public function assignRole(User $user, string $roleName): User
    {
        $role = Role::query()
            ->where('name', $roleName)
            ->where('guard_name', $user->getDefaultGuardName())
            ->firstOrFail();

        return $this->repository->assignRole($user, $role);
    }

    public function syncPermissions(User $user, array $permissionNames): User
    {
        return $this->repository->syncDirectPermissions($user, array_values(array_unique($permissionNames)));
    }
}
