<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'super-admin' => [
                'tenant.view', 'tenant.manage', 'users.manage', 'roles.manage', 'reports.view',
            ],
            'tenant' => [
                'organizations.manage', 'locations.manage', 'contractors.manage', 'users.manage', 'reports.view',
            ],
            'isg' => [
                'locations.view', 'contractors.view', 'visits.manage', 'documents.manage', 'findings.manage', 'reports.view',
            ],
            'contractor' => [
                'contractor.view', 'personnel.manage', 'assets.manage', 'documents.manage', 'visits.view',
            ],
            'security' => [],
            'operation' => [
                'organizations.view', 'locations.view', 'contractors.view', 'visits.view', 'documents.view', 'reports.view',
            ],
        ];

        $permissionNames = collect($roles)->flatten()->unique()->values();
        $permissions = $permissionNames->mapWithKeys(function (string $name): array {
            return [$name => Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ])];
        });

        foreach ($roles as $roleName => $permissionNamesForRole) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
            ]);

            $permissionsForRole = $roleName === 'super-admin'
                ? $permissions->values()->all()
                : collect($permissionNamesForRole)
                    ->map(fn (string $name) => $permissions[$name])
                    ->all();

            $role->syncPermissions($permissionsForRole);
        }
    }
}
