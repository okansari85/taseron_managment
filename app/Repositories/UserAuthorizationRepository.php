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
        return User::query()->with(['roles', 'permissions', 'scopes'])->orderBy('name')->get();
    }

    public function find(int $id): User
    {
        return User::query()->with(['roles', 'permissions', 'scopes'])->findOrFail($id);
    }

    public function assignRole(User $user, Role $role): User
    {
        $user->syncRoles([$role]);
        return $user->load(['roles', 'permissions', 'scopes']);
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
        return $user->load(['roles', 'permissions', 'scopes']);
    }
}
