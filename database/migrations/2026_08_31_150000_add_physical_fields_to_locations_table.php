<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->string('image')->nullable()->after('name');
            $table->text('address')->nullable()->after('image');
            $table->foreignId('city_id')->nullable()->after('address')->constrained('cities')->nullOnDelete();
            $table->foreignId('district_id')->nullable()->after('city_id')->constrained('districts')->nullOnDelete();
            $table->decimal('latitude', 10, 7)->nullable()->after('district_id');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->boolean('is_active')->default(true)->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropForeign(['city_id']);
            $table->dropForeign(['district_id']);
            $table->dropColumn([
                'tenant_id',
                'image',
                'address',
                'city_id',
                'district_id',
                'latitude',
                'longitude',
                'is_active',
            ]);
        });
    }
};