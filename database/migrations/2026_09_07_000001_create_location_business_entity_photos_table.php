<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_business_entity_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_business_entity_id')->constrained('location_business_entities', 'id', 'lbe_photos_lbe_id_foreign')->cascadeOnDelete();
            $table->string('photo_path');
            $table->unsignedInteger('order_no')->default(0);
            $table->timestamps();
        });

        if (Schema::hasColumn('location_business_entities', 'photo_path')) {
            $now = now();

            DB::table('location_business_entities')
                ->whereNotNull('photo_path')
                ->orderBy('id')
                ->get(['id', 'photo_path'])
                ->each(function ($row) use ($now) {
                    DB::table('location_business_entity_photos')->insert([
                        'location_business_entity_id' => $row->id,
                        'photo_path' => $row->photo_path,
                        'order_no' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                });

            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->dropColumn('photo_path');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('location_business_entities', 'photo_path')) {
            Schema::table('location_business_entities', function (Blueprint $table) {
                $table->string('photo_path')->nullable()->after('sgk_workplace_number');
            });
        }

        DB::table('location_business_entity_photos')
            ->where('order_no', 0)
            ->get(['location_business_entity_id', 'photo_path'])
            ->each(function ($row) {
                DB::table('location_business_entities')
                    ->where('id', $row->location_business_entity_id)
                    ->update(['photo_path' => $row->photo_path]);
            });

        Schema::dropIfExists('location_business_entity_photos');
    }
};
