<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_entities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('monitored_page_id')->index();
            $table->uuid('page_snapshot_id')->index();
            $table->uuid('page_content_extraction_id')->nullable()->index();
            $table->string('entity_type', 40)->index();
            $table->string('entity_key', 191)->index();
            $table->string('entity_name', 220);
            $table->string('source_type', 80)->nullable()->index();
            $table->string('source_ref_type', 120)->nullable();
            $table->string('source_ref_id', 191)->nullable();
            $table->unsignedInteger('mention_count')->default(0);
            $table->unsignedInteger('first_position')->nullable();
            $table->decimal('prominence_score', 8, 2)->default(0);
            $table->decimal('confidence_score', 8, 2)->default(0);
            $table->json('evidence_json')->nullable();
            $table->string('analysis_method', 80)->default('deterministic_match');
            $table->string('model_used', 120)->default('deterministic-entity-v1');
            $table->string('analyzer_version', 80)->default('page-entity-analyzer-v1');
            $table->timestamp('observed_at')->nullable()->index();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('monitored_page_id')->references('id')->on('monitored_pages')->cascadeOnDelete();
            $table->foreign('page_snapshot_id')->references('id')->on('page_snapshots')->cascadeOnDelete();
            $table->foreign('page_content_extraction_id')->references('id')->on('page_content_extractions')->nullOnDelete();
            $table->unique(['page_snapshot_id', 'entity_type', 'entity_key'], 'page_entities_snapshot_type_key_unique');
            $table->index(['workspace_id', 'entity_type', 'entity_key'], 'page_entities_workspace_type_key_idx');
        });

        Schema::create('page_mentions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('monitored_page_id')->index();
            $table->uuid('page_snapshot_id')->index();
            $table->uuid('page_content_extraction_id')->nullable()->index();
            $table->uuid('page_entity_id')->nullable()->index();
            $table->string('mention_type', 40)->index();
            $table->string('entity_type', 40)->index();
            $table->string('entity_key', 191)->index();
            $table->string('entity_name', 220);
            $table->string('matched_text', 220);
            $table->string('source_field', 80)->default('main_text');
            $table->unsignedInteger('position_start')->nullable();
            $table->unsignedInteger('position_end')->nullable();
            $table->text('evidence_snippet')->nullable();
            $table->decimal('confidence_score', 8, 2)->default(0);
            $table->timestamp('observed_at')->nullable()->index();
            $table->string('analysis_method', 80)->default('deterministic_match');
            $table->string('model_used', 120)->default('deterministic-entity-v1');
            $table->char('dedupe_hash', 64);
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('monitored_page_id')->references('id')->on('monitored_pages')->cascadeOnDelete();
            $table->foreign('page_snapshot_id')->references('id')->on('page_snapshots')->cascadeOnDelete();
            $table->foreign('page_content_extraction_id')->references('id')->on('page_content_extractions')->nullOnDelete();
            $table->foreign('page_entity_id')->references('id')->on('page_entities')->nullOnDelete();
            $table->unique(['workspace_id', 'dedupe_hash'], 'page_mentions_workspace_dedupe_unique');
            $table->index(['page_snapshot_id', 'mention_type', 'entity_key'], 'page_mentions_snapshot_type_key_idx');
        });

        Schema::create('page_topics', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('monitored_page_id')->index();
            $table->uuid('page_snapshot_id')->index();
            $table->uuid('page_content_extraction_id')->nullable()->index();
            $table->string('topic_key', 191)->index();
            $table->string('topic_name', 220);
            $table->string('topic_type', 80)->default('theme')->index();
            $table->string('source_type', 80)->nullable()->index();
            $table->string('source_ref_type', 120)->nullable();
            $table->string('source_ref_id', 191)->nullable();
            $table->unsignedInteger('mention_count')->default(0);
            $table->unsignedInteger('first_position')->nullable();
            $table->decimal('prominence_score', 8, 2)->default(0);
            $table->decimal('confidence_score', 8, 2)->default(0);
            $table->json('keywords_json')->nullable();
            $table->json('evidence_json')->nullable();
            $table->string('classification_method', 80)->default('deterministic_match');
            $table->string('model_used', 120)->default('deterministic-topic-v1');
            $table->string('classifier_version', 80)->default('page-topic-classifier-v1');
            $table->timestamp('classified_at')->nullable()->index();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('monitored_page_id')->references('id')->on('monitored_pages')->cascadeOnDelete();
            $table->foreign('page_snapshot_id')->references('id')->on('page_snapshots')->cascadeOnDelete();
            $table->foreign('page_content_extraction_id')->references('id')->on('page_content_extractions')->nullOnDelete();
            $table->unique(['page_snapshot_id', 'topic_key'], 'page_topics_snapshot_key_unique');
            $table->index(['workspace_id', 'topic_type', 'confidence_score'], 'page_topics_workspace_type_confidence_idx');
        });

        Schema::create('page_sentiments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('monitored_page_id')->index();
            $table->uuid('page_snapshot_id')->index();
            $table->uuid('page_content_extraction_id')->nullable()->index();
            $table->string('target_type', 40)->index();
            $table->string('target_key', 191)->index();
            $table->string('target_name', 220)->nullable();
            $table->string('target_ref_type', 120)->nullable();
            $table->string('target_ref_id', 191)->nullable();
            $table->decimal('compound_score', 8, 4)->default(0);
            $table->string('label', 40)->default('neutral')->index();
            $table->decimal('confidence_score', 8, 2)->default(0);
            $table->string('analysis_method', 80)->default('lexicon');
            $table->string('model_used', 120)->default('deterministic-sentiment-v1');
            $table->string('analyzer_version', 80)->default('page-sentiment-analyzer-v1');
            $table->text('explanation')->nullable();
            $table->json('evidence_json')->nullable();
            $table->timestamp('analyzed_at')->nullable()->index();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('monitored_page_id')->references('id')->on('monitored_pages')->cascadeOnDelete();
            $table->foreign('page_snapshot_id')->references('id')->on('page_snapshots')->cascadeOnDelete();
            $table->foreign('page_content_extraction_id')->references('id')->on('page_content_extractions')->nullOnDelete();
            $table->unique(['page_snapshot_id', 'target_type', 'target_key'], 'page_sentiments_snapshot_target_unique');
            $table->index(['workspace_id', 'target_type', 'label'], 'page_sentiments_workspace_target_label_idx');
        });

        Schema::create('page_scores', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('monitored_page_id')->index();
            $table->uuid('page_snapshot_id')->index();
            $table->uuid('page_content_extraction_id')->nullable()->index();
            $table->string('score_type', 80)->index();
            $table->decimal('score', 8, 2)->default(0);
            $table->decimal('previous_score', 8, 2)->nullable();
            $table->decimal('delta', 8, 2)->nullable();
            $table->string('score_version', 80)->default('page-basic-score-v1')->index();
            $table->string('calculation_method', 80)->default('deterministic');
            $table->string('model_used', 120)->default('deterministic-score-v1');
            $table->text('explanation')->nullable();
            $table->json('breakdown_json')->nullable();
            $table->json('evidence_json')->nullable();
            $table->timestamp('computed_at')->nullable()->index();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('monitored_page_id')->references('id')->on('monitored_pages')->cascadeOnDelete();
            $table->foreign('page_snapshot_id')->references('id')->on('page_snapshots')->cascadeOnDelete();
            $table->foreign('page_content_extraction_id')->references('id')->on('page_content_extractions')->nullOnDelete();
            $table->unique(['page_snapshot_id', 'score_type', 'score_version'], 'page_scores_snapshot_type_version_unique');
            $table->index(['workspace_id', 'score_type', 'computed_at'], 'page_scores_workspace_type_computed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_scores');
        Schema::dropIfExists('page_sentiments');
        Schema::dropIfExists('page_topics');
        Schema::dropIfExists('page_mentions');
        Schema::dropIfExists('page_entities');
    }
};
