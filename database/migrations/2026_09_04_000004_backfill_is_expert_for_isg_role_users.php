<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Existing isg-role users predate the is_expert distinction and were the
     * group meant to show under the Uzmanlar tab, so mark them as experts.
     */
    public function up(): void
    {
        $userIds = $this->isgUserIds();

        if ($userIds->isEmpty()) {
            return;
        }

        DB::table('users')->whereIn('id', $userIds)->update(['is_expert' => true]);
    }

    public function down(): void
    {
        $userIds = $this->isgUserIds();

        if ($userIds->isEmpty()) {
            return;
        }

        DB::table('users')->whereIn('id', $userIds)->update(['is_expert' => false]);
    }

    private function isgUserIds()
    {
        $roles = config('permission.table_names.roles', 'roles');
        $modelHasRoles = config('permission.table_names.model_has_roles', 'model_has_roles');

        $isgRoleId = DB::table($roles)
            ->where('name', 'isg')
            ->where('guard_name', 'web')
            ->value('id');

        if ($isgRoleId === null) {
            return collect();
        }

        return DB::table($modelHasRoles)
            ->where('role_id', $isgRoleId)
            ->where('model_type', User::class)
            ->pluck('model_id');
    }
};
