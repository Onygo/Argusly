<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mentions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->nullable();
            $table->string('title')->nullable();
            $table->longText('content')->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('author')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('sentiment')->nullable()->index();
            $table->unsignedTinyInteger('impact_score')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'published_at'], 'mentions_scope_published_index');
            $table->index(['account_id', 'brand_id', 'sentiment'], 'mentions_scope_sentiment_index');
            $table->index(['source_id', 'published_at'], 'mentions_source_published_index');
        });

        Schema::create('mention_entities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mention_id')->constrained()->cascadeOnDelete();
            $table->string('entity_name');
            $table->string('entity_type')->index();
            $table->timestamps();

            $table->index(['mention_id', 'entity_type']);
        });

        Schema::create('mention_relationships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mention_id')->constrained()->cascadeOnDelete();
            $table->string('related_type');
            $table->unsignedBigInteger('related_id');
            $table->timestamps();

            $table->index(['related_type', 'related_id']);
            $table->unique(['mention_id', 'related_type', 'related_id'], 'mention_relationship_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mention_relationships');
        Schema::dropIfExists('mention_entities');
        Schema::dropIfExists('mentions');
    }
};
