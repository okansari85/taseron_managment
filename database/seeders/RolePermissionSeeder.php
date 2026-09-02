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
            'tenant-admin' => [
                'organizations.manage', 'locations.manage', 'contractors.manage', 'users.manage', 'reports.view',
            ],
            'tenant-manager' => [
                'organizations.view', 'locations.manage', 'contractors.manage', 'visits.manage', 'documents.manage', 'reports.view',
            ],
            'isg-manager' => [
                'locations.view', 'contractors.view', 'visits.manage', 'documents.manage', 'findings.manage', 'reports.view',
            ],
            'isg-user' => [
                'locations.view', 'contractors.view', 'visits.create', 'documents.view', 'findings.create',
            ],
            'contractor-manager' => [
                'contractor.view', 'personnel.manage', 'assets.manage', 'documents.manage', 'visits.view',
            ],
            'contractor-user' => [
                'contractor.view', 'personnel.manage', 'assets.manage', 'documents.upload', 'visits.view',
            ],
            'viewer' => [
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

            $role->syncPermissions(
                collect($permissionNamesForRole)
                    ->map(fn (string $name) => $permissions[$name])
                    ->all()
            );
        }
    }
}
