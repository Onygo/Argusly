<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_entities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->string('entity_type', 40);
            $table->string('entity_key', 191);
            $table->string('entity_name', 220);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedInteger('mention_count')->default(0);
            $table->unsignedInteger('signal_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->unique(['workspace_id', 'entity_type', 'entity_key'], 'signal_entities_workspace_type_key_unique');
            $table->index(['workspace_id', 'entity_type', 'last_seen_at'], 'signal_entities_workspace_type_seen_idx');
        });

        Schema::create('signal_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->string('type', 60)->index();
            $table->string('name', 220);
            $table->string('status', 40)->default('new')->index();
            $table->json('config')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_processed_at')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->index(['workspace_id', 'type', 'status'], 'signal_sources_workspace_type_status_idx');
        });

        Schema::create('signal_feed_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('signal_source_id')->nullable()->index();
            $table->string('external_id', 191)->nullable();
            $table->string('url', 2048)->nullable();
            $table->char('url_hash', 64)->nullable();
            $table->string('title', 500)->nullable();
            $table->text('summary')->nullable();
            $table->longText('body')->nullable();
            $table->string('author', 220)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->string('language', 20)->nullable();
            $table->json('raw_payload')->nullable();
            $table->char('content_hash', 64);
            $table->string('processing_status', 40)->default('new')->index();
            $table->text('processing_error')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('signal_source_id')->references('id')->on('signal_sources')->nullOnDelete();
            $table->unique(['workspace_id', 'signal_source_id', 'content_hash'], 'signal_feed_items_source_hash_unique');
            $table->unique(['workspace_id', 'url_hash'], 'signal_feed_items_workspace_url_hash_unique');
            $table->index(['workspace_id', 'processing_status', 'published_at'], 'signal_feed_items_workspace_status_published_idx');
        });

        Schema::create('signal_mentions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('signal_feed_item_id')->nullable()->index();
            $table->uuid('signal_entity_id')->nullable()->index();
            $table->string('source_type', 60)->index();
            $table->string('source_ref_type', 100)->nullable();
            $table->string('source_ref_id', 191)->nullable();
            $table->string('mention_type', 40)->index();
            $table->string('entity_type', 40)->index();
            $table->string('entity_name', 220);
            $table->string('entity_key', 191)->index();
            $table->string('canonical_entity_id', 191)->nullable();
            $table->string('url', 2048)->nullable();
            $table->char('url_hash', 64)->nullable();
            $table->text('context')->nullable();
            $table->string('sentiment_label', 40)->nullable();
            $table->decimal('sentiment_score', 8, 2)->nullable();
            $table->decimal('position_score', 8, 2)->nullable();
            $table->decimal('confidence_score', 8, 2)->default(50);
            $table->timestamp('observed_at')->index();
            $table->json('metadata')->nullable();
            $table->char('dedupe_hash', 64)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('signal_feed_item_id')->references('id')->on('signal_feed_items')->nullOnDelete();
            $table->foreign('signal_entity_id')->references('id')->on('signal_entities')->nullOnDelete();
            $table->unique(['workspace_id', 'dedupe_hash'], 'signal_mentions_workspace_dedupe_unique');
            $table->index(['workspace_id', 'mention_type', 'observed_at'], 'signal_mentions_workspace_type_observed_idx');
        });

        Schema::create('signal_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('signal_source_id')->nullable()->index();
            $table->uuid('signal_feed_item_id')->nullable()->index();
            $table->uuid('signal_mention_id')->nullable()->index();
            $table->uuid('signal_entity_id')->nullable()->index();
            $table->string('category', 60)->index();
            $table->string('type', 80)->index();
            $table->string('severity', 40)->default('info')->index();
            $table->string('status', 40)->default('new')->index();
            $table->string('topic', 220)->nullable()->index();
            $table->string('entity_name', 220)->nullable();
            $table->string('entity_key', 191)->nullable()->index();
            $table->decimal('signal_strength', 8, 2)->default(0);
            $table->decimal('confidence_score', 8, 2)->default(50);
            $table->decimal('impact_score', 8, 2)->nullable();
            $table->decimal('urgency_score', 8, 2)->nullable();
            $table->decimal('risk_score', 8, 2)->nullable();
            $table->decimal('opportunity_score', 8, 2)->nullable();
            $table->timestamp('observed_at')->index();
            $table->timestamp('expires_at')->nullable();
            $table->json('evidence')->nullable();
            $table->json('metrics')->nullable();
            $table->json('metadata')->nullable();
            $table->char('dedupe_hash', 64)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('signal_source_id')->references('id')->on('signal_sources')->nullOnDelete();
            $table->foreign('signal_feed_item_id')->references('id')->on('signal_feed_items')->nullOnDelete();
            $table->foreign('signal_mention_id')->references('id')->on('signal_mentions')->nullOnDelete();
            $table->foreign('signal_entity_id')->references('id')->on('signal_entities')->nullOnDelete();
            $table->unique(['workspace_id', 'dedupe_hash'], 'signal_events_workspace_dedupe_unique');
            $table->index(['workspace_id', 'category', 'status', 'observed_at'], 'signal_events_workspace_category_status_observed_idx');
        });

        Schema::create('signal_detections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->string('category', 80)->index();
            $table->string('type', 80)->index();
            $table->string('status', 40)->default('new')->index();
            $table->string('title', 220);
            $table->text('summary')->nullable();
            $table->string('primary_topic', 220)->nullable()->index();
            $table->string('primary_entity', 220)->nullable()->index();
            $table->string('severity', 40)->default('info')->index();
            $table->decimal('priority_score', 8, 2)->default(0)->index();
            $table->decimal('confidence_score', 8, 2)->default(50);
            $table->decimal('impact_score', 8, 2)->default(50);
            $table->decimal('urgency_score', 8, 2)->default(50);
            $table->decimal('risk_score', 8, 2)->nullable();
            $table->decimal('opportunity_score', 8, 2)->nullable();
            $table->json('score_breakdown')->nullable();
            $table->json('evidence_summary')->nullable();
            $table->json('recommended_actions')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->char('dedupe_hash', 64)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->unique(['workspace_id', 'dedupe_hash'], 'signal_detections_workspace_dedupe_unique');
            $table->index(['workspace_id', 'category', 'status', 'priority_score'], 'signal_detections_workspace_category_status_priority_idx');
        });

        Schema::create('signal_detection_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('signal_detection_id')->index();
            $table->uuid('signal_event_id')->index();
            $table->decimal('weight', 8, 2)->default(1);
            $table->json('contribution')->nullable();
            $table->timestamps();

            $table->foreign('signal_detection_id')->references('id')->on('signal_detections')->cascadeOnDelete();
            $table->foreign('signal_event_id')->references('id')->on('signal_events')->cascadeOnDelete();
            $table->unique(['signal_detection_id', 'signal_event_id'], 'signal_detection_links_unique');
        });

        Schema::create('signal_scores', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->string('scope_type', 80)->index();
            $table->string('scope_key', 191)->index();
            $table->string('score_type', 80)->index();
            $table->decimal('score', 8, 2)->default(0);
            $table->decimal('previous_score', 8, 2)->nullable();
            $table->decimal('delta', 8, 2)->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->json('breakdown')->nullable();
            $table->timestamp('computed_at')->index();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->index(['workspace_id', 'score_type', 'computed_at'], 'signal_scores_workspace_type_computed_idx');
            $table->index(['workspace_id', 'scope_type', 'scope_key'], 'signal_scores_workspace_scope_idx');
        });

        Schema::create('signal_processing_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('signal_source_id')->nullable()->index();
            $table->string('run_type', 80)->index();
            $table->string('status', 40)->default('new')->index();
            $table->json('input')->nullable();
            $table->json('result')->nullable();
            $table->unsignedInteger('items_seen')->default(0);
            $table->unsignedInteger('items_created')->default(0);
            $table->unsignedInteger('signals_created')->default(0);
            $table->unsignedInteger('detections_created')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('signal_source_id')->references('id')->on('signal_sources')->nullOnDelete();
            $table->index(['workspace_id', 'run_type', 'status'], 'signal_runs_workspace_type_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_processing_runs');
        Schema::dropIfExists('signal_scores');
        Schema::dropIfExists('signal_detection_links');
        Schema::dropIfExists('signal_detections');
        Schema::dropIfExists('signal_events');
        Schema::dropIfExists('signal_mentions');
        Schema::dropIfExists('signal_feed_items');
        Schema::dropIfExists('signal_sources');
        Schema::dropIfExists('signal_entities');
    }
};
