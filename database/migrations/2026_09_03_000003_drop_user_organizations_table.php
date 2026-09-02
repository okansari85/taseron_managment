<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('user_organizations');
    }

    public function down(): void
    {
        // Intentionally left empty: user organization membership is replaced by user_scopes.
    }
};
