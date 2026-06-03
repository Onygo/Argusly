<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('narratives', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description');
            $table->string('narrative_type')->index();
            $table->string('status')->default('draft')->index();
            $table->string('importance')->index();
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'narrative_type', 'status'], 'narratives_scope_type_status_index');
        });

        Schema::create('narrative_observations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('narrative_id')->constrained()->cascadeOnDelete();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('observation');
            $table->string('sentiment')->nullable();
            $table->unsignedInteger('confidence_score')->nullable();
            $table->timestamp('detected_at');
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'narrative_id'], 'narrative_observations_scope_index');
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('narrative_gaps', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('narrative_id')->constrained()->cascadeOnDelete();
            $table->text('desired_state');
            $table->text('detected_state');
            $table->unsignedInteger('gap_score')->nullable();
            $table->string('status')->default('new')->index();
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'status'], 'narrative_gaps_scope_status_index');
        });

        Schema::create('narrative_topics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('narrative_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['narrative_id', 'topic_id']);
        });

        Schema::create('narrative_entities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('narrative_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entity_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['narrative_id', 'entity_id']);
        });

        Schema::create('narrative_mentions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('narrative_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mention_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['narrative_id', 'mention_id']);
        });

        Schema::create('narrative_competitors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('narrative_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competitor_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['narrative_id', 'competitor_id']);
        });

        Schema::create('narrative_visibility_provider_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('narrative_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('visibility_provider_run_id');
            $table->timestamps();

            $table->foreign('visibility_provider_run_id', 'narrative_visibility_run_fk')
                ->references('id')
                ->on('visibility_provider_runs')
                ->cascadeOnDelete();
            $table->unique(['narrative_id', 'visibility_provider_run_id'], 'narrative_visibility_runs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('narrative_visibility_provider_runs');
        Schema::dropIfExists('narrative_competitors');
        Schema::dropIfExists('narrative_mentions');
        Schema::dropIfExists('narrative_entities');
        Schema::dropIfExists('narrative_topics');
        Schema::dropIfExists('narrative_gaps');
        Schema::dropIfExists('narrative_observations');
        Schema::dropIfExists('narratives');
    }
};
