<?php

namespace App\Repositories;

use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserAuthorizationRepository
{
    // A user is listed under a tenant if they are a global super-admin, or if
    // one of their scopes resolves to this tenant (a direct tenant scope, an
    // organization scope belonging to this tenant, or a location scope
    // belonging to this tenant). This keeps users created under one tenant
    // from leaking into another tenant's user list.
    public function all(int $tenantId): Collection
    {
        return User::query()
            ->with(['roles', 'permissions', 'forbiddenPermissions', 'scopes', 'contractor'])
            ->where(function ($query) use ($tenantId) {
                $query->whereHas('roles', fn ($role) => $role->where('name', 'super-admin'))
                    ->orWhereHas('scopes', function ($scope) use ($tenantId) {
                        $scope->where(function ($match) use ($tenantId) {
                            $match->where('scope_type', 'tenant')->where('scope_id', $tenantId);
                        })->orWhere(function ($match) use ($tenantId) {
                            $match->where('scope_type', 'organization')
                                ->whereIn('scope_id', Organization::query()->where('tenant_id', $tenantId)->select('id'));
                        })->orWhere(function ($match) use ($tenantId) {
                            $match->where('scope_type', 'location')
                                ->whereIn('scope_id', Location::query()->where('tenant_id', $tenantId)->select('id'));
                        });
                    });
            })
            ->orderBy('name')->get()
            ->each(fn (User $user) => $user->setRelation('permissions', $user->getAllPermissions()));
    }

    public function find(int $id): User
    {
        $user = User::query()->with(['roles', 'permissions', 'forbiddenPermissions', 'scopes', 'contractor'])->findOrFail($id);
        return $user->setRelation('permissions', $user->getAllPermissions());
    }

    public function assignRole(User $user, Role $role): User
    {
        $user->syncRoles([$role]);
        $user->load(['roles', 'permissions', 'forbiddenPermissions', 'scopes', 'contractor']);
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
        $user->load(['roles', 'permissions', 'forbiddenPermissions', 'scopes', 'contractor']);
        return $user->setRelation('permissions', $user->getAllPermissions());
    }

    public function syncUserPermissions(User $user, array $permissionNames): User
    {
        $permissionNames = array_values(array_unique($permissionNames));
        $permissions = Permission::query()
            ->whereIn('name', $permissionNames)
            ->where('guard_name', $user->getDefaultGuardName())
            ->get();

        if ($permissions->count() !== count($permissionNames)) {
            abort(422, 'Bir veya daha fazla permission bulunamadı.');
        }

        $rolePermissions = $user->roles()->with('permissions')->get()
            ->flatMap(fn (Role $role) => $role->permissions)
            ->unique('id')
            ->values();
        $rolePermissionNames = $rolePermissions->pluck('name')->all();
        $selected = array_flip($permissionNames);

        $directPermissions = $permissions
            ->reject(fn (Permission $permission): bool => in_array($permission->name, $rolePermissionNames, true))
            ->values();

        $forbiddenPermissionIds = $rolePermissions
            ->filter(fn (Permission $permission): bool => ! isset($selected[$permission->name]))
            ->pluck('id')
            ->all();

        $user->syncPermissions($directPermissions->all());
        $user->forbiddenPermissions()->sync($forbiddenPermissionIds);
        $user->load(['roles', 'permissions', 'forbiddenPermissions', 'scopes', 'contractor']);

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
        $user->load(['roles', 'permissions', 'forbiddenPermissions', 'scopes', 'contractor']);
        return $user->setRelation('permissions', $user->getAllPermissions());
    }
}
