<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\PermissionRegistrar;

trait HasForbiddenPermissions
{
    use \Spatie\Permission\Traits\HasRoles {
        hasPermissionTo as protected hasPermissionToTrait;
        hasPermissionViaRole as protected hasPermissionViaRoleTrait;
        hasDirectPermission as protected hasDirectPermissionTrait;
        getPermissionsViaRoles as protected getPermissionsViaRolesTrait;
        getAllPermissions as protected getAllPermissionsTrait;
    }

    public static function bootHasForbiddenPermissions(): void
    {
        static::deleting(static function (Model $model): void {
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            $model->forbiddenPermissions()->detach();
        });
    }

    private function getPermissionClassKeyName(): string
    {
        $class = $this->getPermissionClass();
        return (new $class)->getKeyName();
    }

    public function forbiddenPermissions(): BelongsToMany
    {
        $relation = $this->morphToMany(
            config('permission.models.permission'),
            'model',
            config('permission.table_names.model_has_forbidden_permissions'),
            config('permission.column_names.model_morph_key'),
            PermissionRegistrar::$pivotPermission
        );

        if (! PermissionRegistrar::$teams) {
            return $relation;
        }

        return $relation->wherePivot(PermissionRegistrar::$teamsKey, getPermissionsTeamId());
    }

    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        if ($this->hasForbiddenPermission($permission)) {
            return false;
        }

        return $this->hasPermissionToTrait($permission, $guardName);
    }

    public function hasForbiddenPermission($permission): bool
    {
        $permissionFound = $this->filterPermission($permission);

        return $this->forbiddenPermissions->contains(
            $permissionFound->getKeyName(),
            $permissionFound->getKey()
        );
    }

    public function forbidPermissionTo(string|array|Collection $permissions = []): static
    {
        $permissions = $this->collectPermissions($permissions);
        $model = $this->getModel();

        if ($model->exists) {
            $this->forbiddenPermissions()->sync($permissions, false);
            $model->load('forbiddenPermissions');
        } else {
            $class = get_class($model);
            $class::saved(static function ($object) use ($permissions, $model): void {
                if ($model->getKey() !== $object->getKey()) {
                    return;
                }
                $model->forbiddenPermissions()->sync($permissions, false);
                $model->load('forbiddenPermissions');
            });
        }

        return $this;
    }

    public function unforbidPermissionTo(string|array|Collection $permissions): static
    {
        $this->forbiddenPermissions()->detach(array_keys($this->collectPermissions($permissions)));
        $this->load('forbiddenPermissions');
        return $this;
    }

    protected function hasPermissionViaRole(Permission $permission): bool
    {
        if ($this->hasForbiddenPermission($permission)) {
            return false;
        }

        return $this->hasPermissionViaRoleTrait($permission);
    }

    public function hasDirectPermission($permission): bool
    {
        if ($this->hasForbiddenPermission($permission)) {
            return false;
        }

        return $this->hasDirectPermissionTrait($permission);
    }

    public function getPermissionsViaRoles(): Collection
    {
        $keyName = $this->getPermissionClassKeyName();
        return $this->getPermissionsViaRolesTrait()
            ->whereNotIn($keyName, $this->forbiddenPermissions->pluck($keyName)->toArray())
            ->values();
    }

    public function getAllPermissions(): Collection
    {
        $keyName = $this->getPermissionClassKeyName();
        return $this->getAllPermissionsTrait()
            ->whereNotIn($keyName, $this->forbiddenPermissions->pluck($keyName)->toArray())
            ->values();
    }

    public function syncForbiddenPermissions(string|array|Collection $permissions = []): static
    {
        $this->forbiddenPermissions()->detach();
        return $this->forbidPermissionTo($permissions);
    }

    public function getForbiddenPermissionNames(): Collection
    {
        return $this->forbiddenPermissions->pluck('name');
    }

    private function collectPermissions(string|array|Collection $permissions): array
    {
        return collect($permissions)->flatten()->reduce(function (array $array, $permission): array {
            if (empty($permission)) {
                return $array;
            }
            $permission = $this->getStoredPermission($permission);
            if (! $permission instanceof Permission) {
                return $array;
            }
            $this->ensureModelSharesGuard($permission);
            $array[$permission->getKey()] = [];
            return $array;
        }, []);
    }
}
