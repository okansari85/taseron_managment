<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Permission;

trait HasForbiddenPermissions
{
    public function forbiddenPermissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'model_has_forbidden_permissions',
            'user_id',
            'permission_id'
        );
    }

    public function hasForbiddenPermission($permission): bool
    {
        $permission = $this->filterPermission($permission);

        return $this->loadMissing('forbiddenPermissions')->forbiddenPermissions
            ->contains($permission->getKeyName(), $permission->getKey());
    }

    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        if ($this->hasForbiddenPermission($permission)) {
            return false;
        }

        return $this->spatieHasPermissionTo($permission, $guardName);
    }

    public function getEffectivePermissions()
    {
        return $this->getAllPermissions()
            ->reject(fn (Permission $permission): bool => $this->hasForbiddenPermission($permission))
            ->values();
    }
}
