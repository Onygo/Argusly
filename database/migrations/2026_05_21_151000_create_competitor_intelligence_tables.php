<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitor_intelligence_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->uuid('workspace_id');
            $table->uuid('client_site_id')->nullable();
            $table->foreignId('site_competitor_id')->nullable()->constrained('site_competitors')->nullOnDelete();
            $table->string('status', 40)->default('queued');
            $table->string('source_type', 60)->default('internal_import');
            $table->string('cache_key', 191)->nullable();
            $table->unsignedInteger('content_items_count')->default(0);
            $table->unsignedInteger('topics_count')->default(0);
            $table->unsignedInteger('opportunities_count')->default(0);
            $table->json('input')->nullable();
            $table->json('result')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status'], 'competitor_runs_workspace_status_idx');
            $table->index(['client_site_id', 'site_competitor_id'], 'competitor_runs_site_competitor_idx');
        });

        Schema::create('competitor_content_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->uuid('workspace_id');
            $table->uuid('client_site_id')->nullable();
            $table->foreignId('site_competitor_id')->constrained('site_competitors')->cascadeOnDelete();
            $table->string('source_type', 60)->default('manual_import');
            $table->string('url', 2048)->nullable();
            $table->string('url_hash', 64);
            $table->string('title', 255)->nullable();
            $table->text('meta_description')->nullable();
            $table->longText('content_excerpt')->nullable();
            $table->longText('normalized_text')->nullable();
            $table->string('content_type', 80)->default('page');
            $table->string('content_format', 80)->default('article');
            $table->string('query_intent', 80)->default('informational');
            $table->string('funnel_stage', 40)->default('tofu');
            $table->string('landing_page_angle', 120)->nullable();
            $table->boolean('is_comparison_page')->default(false);
            $table->boolean('has_answer_block_pattern')->default(false);
            $table->boolean('has_schema_pattern')->default(false);
            $table->json('detected_topics')->nullable();
            $table->json('detected_entities')->nullable();
            $table->json('seo_patterns')->nullable();
            $table->json('aeo_patterns')->nullable();
            $table->json('normalized_payload')->nullable();
            $table->string('normalized_payload_hash', 64)->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'site_competitor_id', 'url_hash'], 'competitor_content_unique_url');
            $table->index(['workspace_id', 'query_intent', 'funnel_stage'], 'competitor_content_intent_idx');
            $table->index(['site_competitor_id', 'content_format'], 'competitor_content_format_idx');
        });

        Schema::create('competitor_topic_signals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->uuid('workspace_id');
            $table->uuid('client_site_id')->nullable();
            $table->foreignId('site_competitor_id')->nullable()->constrained('site_competitors')->nullOnDelete();
            $table->string('topic', 191);
            $table->string('topic_hash', 64);
            $table->unsignedInteger('competitor_content_count')->default(0);
            $table->unsignedInteger('argusly_content_count')->default(0);
            $table->decimal('overlap_score', 5, 2)->default(0);
            $table->decimal('opportunity_score', 5, 2)->default(0);
            $table->string('coverage_status', 40)->default('missing');
            $table->json('intent_mix')->nullable();
            $table->json('formats')->nullable();
            $table->json('entities')->nullable();
            $table->json('examples')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'site_competitor_id', 'topic_hash'], 'competitor_topic_unique');
            $table->index(['workspace_id', 'coverage_status', 'opportunity_score'], 'competitor_topic_opportunity_idx');
        });

        Schema::create('competitor_content_opportunities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->uuid('workspace_id');
            $table->uuid('client_site_id')->nullable();
            $table->foreignId('site_competitor_id')->nullable()->constrained('site_competitors')->nullOnDelete();
            $table->uuid('competitor_intelligence_run_id')->nullable();
            $table->string('type', 80);
            $table->string('status', 40)->default('open');
            $table->string('title', 255);
            $table->string('topic', 191)->nullable();
            $table->string('query_intent', 80)->nullable();
            $table->string('funnel_stage', 40)->nullable();
            $table->string('recommended_format', 120)->nullable();
            $table->decimal('priority_score', 5, 2)->default(0);
            $table->decimal('confidence_score', 5, 2)->default(0);
            $table->decimal('impact_score', 5, 2)->default(0);
            $table->decimal('effort_score', 5, 2)->default(0);
            $table->text('attackable_angle')->nullable();
            $table->text('reason')->nullable();
            $table->json('competitor_evidence')->nullable();
            $table->json('argusly_coverage')->nullable();
            $table->json('normalized_payload')->nullable();
            $table->string('dedupe_hash', 64);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'dedupe_hash'], 'competitor_opportunity_unique');
            $table->index(['workspace_id', 'status', 'priority_score'], 'competitor_opportunity_priority_idx');
            $table->foreign('competitor_intelligence_run_id', 'competitor_opportunities_run_fk')
                ->references('id')
                ->on('competitor_intelligence_runs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_content_opportunities');
        Schema::dropIfExists('competitor_topic_signals');
        Schema::dropIfExists('competitor_content_items');
        Schema::dropIfExists('competitor_intelligence_runs');
    }
};
