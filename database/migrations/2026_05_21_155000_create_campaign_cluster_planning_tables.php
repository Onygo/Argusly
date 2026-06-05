<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_cluster_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id');
            $table->uuid('client_site_id')->nullable();
            $table->string('status', 40)->default('running')->index();
            $table->string('source_type', 80)->default('agentic_marketing');
            $table->unsignedInteger('clusters_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('refreshed_count')->default(0);
            $table->json('input')->nullable();
            $table->json('result')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->index(['workspace_id', 'status'], 'campaign_cluster_runs_workspace_status_idx');
        });

        Schema::create('campaign_clusters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id');
            $table->uuid('client_site_id')->nullable();
            $table->uuid('campaign_cluster_run_id')->nullable();
            $table->string('status', 40)->default('draft')->index();
            $table->string('name', 255);
            $table->string('primary_entity', 255);
            $table->string('primary_topic', 255);
            $table->string('authority_strategy', 255);
            $table->string('cta_strategy', 255)->nullable();
            $table->string('refresh_cadence', 80)->default('quarterly');
            $table->date('planned_start_date')->nullable();
            $table->date('planned_end_date')->nullable();
            $table->decimal('authority_score', 5, 2)->default(0);
            $table->decimal('topical_coverage_score', 5, 2)->default(0);
            $table->decimal('funnel_coverage_score', 5, 2)->default(0);
            $table->decimal('ai_visibility_score', 5, 2)->default(0);
            $table->decimal('completeness_score', 5, 2)->default(0);
            $table->json('funnel_coverage')->nullable();
            $table->json('internal_link_architecture')->nullable();
            $table->json('localization_strategy')->nullable();
            $table->json('publishing_sequence')->nullable();
            $table->json('timeline')->nullable();
            $table->json('visual_map')->nullable();
            $table->json('missing_coverage')->nullable();
            $table->json('authority_gaps')->nullable();
            $table->json('source_signals')->nullable();
            $table->string('dedupe_hash', 64);
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('campaign_cluster_run_id')->references('id')->on('campaign_cluster_runs')->nullOnDelete();
            $table->unique(['workspace_id', 'dedupe_hash'], 'campaign_clusters_workspace_dedupe_unique');
            $table->index(['workspace_id', 'status'], 'campaign_clusters_workspace_status_idx');
        });

        Schema::create('campaign_cluster_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('campaign_cluster_id');
            $table->uuid('content_opportunity_id')->nullable();
            $table->uuid('content_id')->nullable();
            $table->string('type', 80);
            $table->string('status', 40)->default('planned')->index();
            $table->string('title', 255);
            $table->string('target_entity', 255)->nullable();
            $table->string('funnel_stage', 80)->nullable();
            $table->string('search_intent', 80)->nullable();
            $table->unsignedInteger('sequence_order')->default(0);
            $table->date('planned_publish_date')->nullable();
            $table->decimal('authority_contribution', 5, 2)->default(0);
            $table->decimal('coverage_contribution', 5, 2)->default(0);
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->foreign('campaign_cluster_id')->references('id')->on('campaign_clusters')->cascadeOnDelete();
            $table->foreign('content_opportunity_id')->references('id')->on('content_opportunities')->nullOnDelete();
            $table->foreign('content_id')->references('id')->on('contents')->nullOnDelete();
            $table->index(['campaign_cluster_id', 'type'], 'campaign_cluster_items_cluster_type_idx');
        });

        Schema::create('campaign_cluster_dependencies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('campaign_cluster_id');
            $table->uuid('source_item_id');
            $table->uuid('target_item_id');
            $table->string('type', 80)->default('internal_link');
            $table->string('anchor_text', 255)->nullable();
            $table->string('reason', 255)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->foreign('campaign_cluster_id')->references('id')->on('campaign_clusters')->cascadeOnDelete();
            $table->foreign('source_item_id')->references('id')->on('campaign_cluster_items')->cascadeOnDelete();
            $table->foreign('target_item_id')->references('id')->on('campaign_cluster_items')->cascadeOnDelete();
            $table->index(['campaign_cluster_id', 'type'], 'campaign_cluster_dependencies_cluster_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_cluster_dependencies');
        Schema::dropIfExists('campaign_cluster_items');
        Schema::dropIfExists('campaign_clusters');
        Schema::dropIfExists('campaign_cluster_runs');
    }
};
