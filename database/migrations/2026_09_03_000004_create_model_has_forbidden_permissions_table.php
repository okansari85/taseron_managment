<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $permissionPivotKey = config('permission.column_names.permission_pivot_key', 'permission_id');

        Schema::create('model_has_forbidden_permissions', function (Blueprint $table) use ($permissionPivotKey): void {
            $table->unsignedBigInteger($permissionPivotKey);
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'model_has_forbidden_permissions_model_id_model_type_index');
            $table->foreign($permissionPivotKey)
                ->references('id')
                ->on('permissions')
                ->onDelete('cascade');
            $table->primary(
                [$permissionPivotKey, 'model_id', 'model_type'],
                'model_has_forbidden_permissions_permission_model_type_primary'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_has_forbidden_permissions');
    }
};
