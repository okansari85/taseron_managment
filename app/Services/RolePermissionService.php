<?php

namespace App\Services;

use App\Repositories\RolePermissionRepository;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Role;

class RolePermissionService
{
    public function __construct(private RolePermissionRepository $repository)
    {
    }

    public function roles(): Collection
    {
        return $this->repository->roles();
    }

    public function permissions(): Collection
    {
        return $this->repository->permissions();
    }

    public function sync(string $roleName, array $permissionNames): Role
    {
        return $this->repository->syncRolePermissions(
            $this->repository->findRole($roleName),
            array_values(array_unique($permissionNames))
        );
    }
}
