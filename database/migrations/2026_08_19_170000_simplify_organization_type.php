<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Company, Brand and Location are separate domain entities.
         * Organization type is only a classification for the
         * organization hierarchy itself.
         */
        DB::table('organizations')
            ->whereNotIn('type', ['holding', 'group'])
            ->update(['type' => null]);

        Schema::table('organizations', function (Blueprint $table) {
            $table->enum('type', [
                'holding',
                'group',
            ])
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        DB::table('organizations')
            ->whereNull('type')
            ->update(['type' => 'company']);

        Schema::table('organizations', function (Blueprint $table) {
            $table->enum('type', [
                'holding',
                'group',
                'company',
                'brand',
                'location',
            ])
                ->nullable(false)
                ->change();
        });
    }
};
