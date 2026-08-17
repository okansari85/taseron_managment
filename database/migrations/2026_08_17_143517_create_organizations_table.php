<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('organizations')
                ->restrictOnDelete();

            $table->string('name');

            $table->enum('type', [
                'holding',
                'group',
                'company',
                'brand',
                'location',
            ]);

            $table->timestamps();

            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};