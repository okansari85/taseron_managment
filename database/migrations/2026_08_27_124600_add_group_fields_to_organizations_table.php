<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
            $table->text('description')->nullable()->after('slug');
            $table->string('code', 100)->nullable()->after('description');
            $table->unsignedInteger('display_order')->default(0)->after('code');
            $table->boolean('is_active')->default(true)->after('display_order');
            $table->string('color', 7)->default('#465FFF')->after('is_active');

            $table->unique(['tenant_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropUnique('organizations_tenant_id_slug_unique');
            $table->dropColumn([
                'slug',
                'description',
                'code',
                'display_order',
                'is_active',
                'color',
            ]);
        });
    }
};
