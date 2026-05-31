<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entities', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('aliases')->nullable();
            $table->string('entity_type')->index();
            $table->timestamps();

            $table->unique(['account_id', 'name', 'entity_type']);
            $table->index(['account_id', 'entity_type']);
        });

        Schema::create('brand_entities', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entity_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['account_id', 'brand_id', 'entity_id']);
            $table->index(['account_id', 'brand_id']);
        });

        Schema::create('entity_relationships', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('source_entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignId('target_entity_id')->constrained('entities')->cascadeOnDelete();
            $table->string('relationship_type')->index();
            $table->timestamps();

            $table->unique(['account_id', 'brand_id', 'source_entity_id', 'target_entity_id', 'relationship_type'], 'entity_relationship_scope_unique');
            $table->index(['account_id', 'brand_id', 'relationship_type'], 'entity_relationship_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_relationships');
        Schema::dropIfExists('brand_entities');
        Schema::dropIfExists('entities');
    }
};
