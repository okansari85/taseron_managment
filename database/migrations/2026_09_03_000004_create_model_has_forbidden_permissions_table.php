<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_has_forbidden_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger(PermissionRegistrar::$pivotPermission);
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'model_has_forbidden_permissions_model_id_model_type_index');
            $table->foreign(PermissionRegistrar::$pivotPermission)
                ->references('id')
                ->on('permissions')
                ->onDelete('cascade');
            $table->primary(
                [PermissionRegistrar::$pivotPermission, 'model_id', 'model_type'],
                'model_has_forbidden_permissions_permission_model_type_primary'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_has_forbidden_permissions');
    }
};
