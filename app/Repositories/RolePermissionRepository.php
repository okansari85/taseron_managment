<?php

namespace App\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionRepository
{
    public function roles(): Collection
    {
        return Role::query()
            ->where('guard_name', $this->defaultGuardName())
            ->with('permissions')
            ->orderBy('name')
            ->get();
    }

    public function permissions(): Collection
    {
        return Permission::query()
            ->where('guard_name', $this->defaultGuardName())
            ->orderBy('name')
            ->get();
    }

    public function findRole(string $roleName, ?string $guardName = null): Role
    {
        $guardName = $guardName ?: $this->defaultGuardName();
        $roleName = trim($roleName);

        $role = Role::query()
            ->where('name', $roleName)
            ->where('guard_name', $guardName)
            ->first();

        if ($role !== null) {
            return $role;
        }

        throw ValidationException::withMessages([
            'role' => "'{$roleName}' rolü '{$guardName}' guard'ı ile bulunamadı.",
        ]);
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

    private function defaultGuardName(): string
    {
        return (string) config('auth.defaults.guard', 'web');
    }
}
