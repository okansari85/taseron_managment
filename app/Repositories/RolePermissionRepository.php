<?php

namespace App\Repositories;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Database\Eloquent\Collection;

class RolePermissionRepository
{
    public function roles(): Collection
    {
        return Role::query()->with('permissions')->orderBy('name')->get();
    }

    public function permissions(): Collection
    {
        return Permission::query()->orderBy('name')->get();
    }

    public function findRole(string $roleName, ?string $guardName = null): Role
    {
        $query = Role::query()->where('name', $roleName);

        if ($guardName !== null && $guardName !== '') {
            $query->where('guard_name', $guardName);
        }

        return $query->firstOrFail();
    }

    public function syncRolePermissions(Role $role, array $permissionNames): Role
    {
        $permissions = Permission::query()
            ->whereIn('name', $permissionNames)
            ->where('guard_name', $role->guard_name)
            ->get();

        if ($permissions->count() !== count(array_unique($permissionNames))) {
            abort(422, 'Bir veya daha fazla permission bulunamadı.');
        }

        $role->syncPermissions($permissions->all());
        return $role->load('permissions');
    }
}
