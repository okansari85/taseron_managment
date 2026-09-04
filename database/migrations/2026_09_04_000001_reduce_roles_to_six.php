<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Consolidate the legacy role set without changing the permission definitions.
     */
    public function up(): void
    {
        $roles = config('permission.table_names.roles', 'roles');
        $modelHasRoles = config('permission.table_names.model_has_roles', 'model_has_roles');

        $renameMap = [
            'tenant-admin' => 'tenant',
            'isg-manager' => 'isg',
            'contractor-manager' => 'contractor',
            'viewer' => 'operation',
        ];

        foreach ($renameMap as $from => $to) {
            $sourceId = DB::table($roles)
                ->where('name', $from)
                ->where('guard_name', 'web')
                ->value('id');

            if ($sourceId === null) {
                continue;
            }

            $targetId = DB::table($roles)
                ->where('name', $to)
                ->where('guard_name', 'web')
                ->value('id');

            if ($targetId === null) {
                DB::table($roles)
                    ->where('id', $sourceId)
                    ->update(['name' => $to]);
            }
        }

        $roleIds = DB::table($roles)
            ->where('guard_name', 'web')
            ->whereIn('name', ['tenant', 'isg', 'contractor', 'operation', 'security', 'super-admin'])
            ->pluck('id', 'name');

        $mergeMap = [
            'tenant-manager' => 'tenant',
            'isg-user' => 'isg',
            'contractor-user' => 'contractor',
        ];

        foreach ($mergeMap as $from => $to) {
            $sourceId = DB::table($roles)
                ->where('name', $from)
                ->where('guard_name', 'web')
                ->value('id');
            $targetId = $roleIds[$to] ?? null;

            if ($sourceId === null || $targetId === null) {
                continue;
            }

            $assignments = DB::table($modelHasRoles)
                ->where('role_id', $sourceId)
                ->get(['model_id', 'model_type']);

            foreach ($assignments as $assignment) {
                $alreadyAssigned = DB::table($modelHasRoles)
                    ->where('role_id', $targetId)
                    ->where('model_id', $assignment->model_id)
                    ->where('model_type', $assignment->model_type)
                    ->exists();

                if (!$alreadyAssigned) {
                    DB::table($modelHasRoles)->insert([
                        'role_id' => $targetId,
                        'model_id' => $assignment->model_id,
                        'model_type' => $assignment->model_type,
                    ]);
                }
            }

            DB::table($modelHasRoles)
                ->where('role_id', $sourceId)
                ->delete();

            DB::table($roles)
                ->where('id', $sourceId)
                ->delete();
        }

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    /**
     * Restore the legacy role names. Merged user assignments remain on the consolidated roles.
     */
    public function down(): void
    {
        $roles = config('permission.table_names.roles', 'roles');

        $renameMap = [
            'tenant' => 'tenant-admin',
            'isg' => 'isg-manager',
            'contractor' => 'contractor-manager',
            'operation' => 'viewer',
        ];

        foreach ($renameMap as $from => $to) {
            DB::table($roles)
                ->where('name', $from)
                ->where('guard_name', 'web')
                ->update(['name' => $to]);
        }

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }
};
