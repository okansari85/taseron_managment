<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->boolean('allows_multiple_branches')->default(false)->after('is_active');
        });

        // Zaten 2 veya daha fazla LocationBusinessEntity'si olan lokasyonlar
        // fiilen çok şubeli (AVM/kampüs) demektir — geriye dönük olarak true
        // işaretlenir. Bundan sonraki lokasyonlarda bu, oluşturma formunda
        // açıkça sorulacak.
        $multiEntityLocationIds = DB::table('location_business_entities')
            ->select('location_id')
            ->groupBy('location_id')
            ->havingRaw('COUNT(*) >= 2')
            ->pluck('location_id');

        if ($multiEntityLocationIds->isNotEmpty()) {
            DB::table('locations')
                ->whereIn('id', $multiEntityLocationIds)
                ->update(['allows_multiple_branches' => true]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('allows_multiple_branches');
        });
    }
};
