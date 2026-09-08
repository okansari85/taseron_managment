<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_document_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained()->cascadeOnDelete();
            $table->string('target'); // company | personnel
            $table->boolean('is_required')->default(true);
            $table->unsignedInteger('validity_days')->nullable(); // null = süresiz
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(
                ['activity_id', 'document_type_id', 'target'],
                'activity_document_types_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_document_types');
    }
};
