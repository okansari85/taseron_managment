<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Location is a physical entity and must never be an Organization node.
        // Existing Location records remain in locations and their many-to-many
        // relationship with organization nodes remains in organization_locations.
        $locationNodes = DB::table('organizations')
            ->where('type', 'location')
            ->pluck('id');

        if ($locationNodes->isNotEmpty()) {
            throw new \RuntimeException(
                'organizations tablosunda location tipinde node bulundu. Önce bu node\'ların organization_locations bağlantılarını ve veri sahipliğini güvenli biçimde taşıyın/silin. Etkilenen ID: ' . $locationNodes->implode(', ')
            );
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->enum('type', [
                'holding',
                'group',
                'company',
                'brand',
            ])->change();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->enum('type', [
                'holding',
                'group',
                'company',
                'brand',
                'location',
            ])->change();
        });
    }
};
