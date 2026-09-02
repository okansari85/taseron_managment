<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserAuthorizationRepository
{
    public function all(): Collection
    {
        return User::query()->with(['roles', 'permissions', 'forbiddenPermissions', 'scopes'])->orderBy('name')->get()
            ->each(fn (User $user) => $user->setRelation('permissions', $user->getAllPermissions()));
    }

    public function find(int $id): User
    {
        $user = User::query()->with(['roles', 'permissions', 'forbiddenPermissions', 'scopes'])->findOrFail($id);
        return $user->setRelation('permissions', $user->getAllPermissions());
    }

    public function assignRole(User $user, Role $role): User
    {
        $user->syncRoles([$role]);
        $user->load(['roles', 'permissions', 'forbiddenPermissions', 'scopes']);
        return $user->setRelation('permissions', $user->getAllPermissions());
    }

    public function syncDirectPermissions(User $user, array $permissionNames): User
    {
        $permissions = Permission::query()
            ->whereIn('name', $permissionNames)
            ->where('guard_name', $user->getDefaultGuardName())
            ->get();

        if ($permissions->count() !== count(array_unique($permissionNames))) {
            abort(422, 'Bir veya daha fazla permission bulunamadı.');
        }

        $user->syncPermissions($permissions->all());
        $user->load(['roles', 'permissions', 'forbiddenPermissions', 'scopes']);
        return $user->setRelation('permissions', $user->getAllPermissions());
    }

    public function syncUserPermissions(User $user, array $permissionNames): User
    {
        $permissionNames = array_values(array_unique($permissionNames));
        $guard = $user->getDefaultGuardName();
        $permissions = Permission::query()
            ->whereIn('name', $permissionNames)
            ->where('guard_name', $guard)
            ->get();

        if ($permissions->count() !== count($permissionNames)) {
            abort(422, 'Bir veya daha fazla permission bulunamadı.');
        }

        $rolePermissions = $user->getPermissionsViaRoles();
        $rolePermissionNames = $rolePermissions->pluck('name')->all();
        $selected = array_flip($permissionNames);
        $rolePermissionIds = $rolePermissions->pluck('id')->all();

        $directPermissions = $permissions
            ->reject(fn (Permission $permission): bool => in_array($permission->name, $rolePermissionNames, true))
            ->values();

        $forbiddenPermissionIds = $rolePermissions
            ->filter(fn (Permission $permission): bool => ! isset($selected[$permission->name]))
            ->modelKeys();

        $user->syncPermissions($directPermissions->all());
        $user->forbiddenPermissions()->sync($forbiddenPermissionIds);
        $user->load(['roles', 'permissions', 'forbiddenPermissions', 'scopes']);

        return $user->setRelation('permissions', $user->getAllPermissions());
    }

    public function syncForbiddenPermissions(User $user, array $permissionNames): User
    {
        $permissions = Permission::query()
            ->whereIn('name', $permissionNames)
            ->where('guard_name', $user->getDefaultGuardName())
            ->get();

        if ($permissions->count() !== count(array_unique($permissionNames))) {
            abort(422, 'Bir veya daha fazla forbidden permission bulunamadı.');
        }

        $user->forbiddenPermissions()->sync($permissions->modelKeys());
        $user->load(['roles', 'permissions', 'forbiddenPermissions', 'scopes']);
        return $user->setRelation('permissions', $user->getAllPermissions());
    }
}
