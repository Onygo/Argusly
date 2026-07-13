<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_growth_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('supersedes_plan_id')->nullable()->index();
            $table->string('status', 40)->default('draft')->index();
            $table->unsignedInteger('version')->default(1);
            $table->string('planning_horizon', 80)->default('next_90_days')->index();
            $table->text('business_objective')->nullable();
            $table->text('brand_objective')->nullable();
            $table->timestamp('generated_at')->nullable()->index();
            $table->timestamp('source_data_cutoff_at')->nullable()->index();
            $table->decimal('confidence_score', 8, 2)->default(0);
            $table->json('confidence_summary')->nullable();
            $table->json('assumptions')->nullable();
            $table->json('missing_information')->nullable();
            $table->json('context_snapshot')->nullable();
            $table->json('recommended_primary_audiences')->nullable();
            $table->json('recommended_secondary_audiences')->nullable();
            $table->json('priority_industries')->nullable();
            $table->json('buying_committee_roles')->nullable();
            $table->json('positioning_observations')->nullable();
            $table->json('messaging_priorities')->nullable();
            $table->json('authority_priorities')->nullable();
            $table->json('evidence_priorities')->nullable();
            $table->json('content_priorities')->nullable();
            $table->json('campaign_themes')->nullable();
            $table->json('channel_recommendations')->nullable();
            $table->json('kpi_recommendations')->nullable();
            $table->json('top_prioritized_actions')->nullable();
            $table->json('generated_by_metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('supersedes_plan_id')->references('id')->on('brand_growth_plans')->nullOnDelete();
            $table->unique(['workspace_id', 'version'], 'brand_growth_plans_workspace_version_unique');
            $table->index(['workspace_id', 'status', 'version'], 'brand_growth_plans_workspace_status_version_idx');
        });

        Schema::create('brand_growth_plan_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('brand_growth_plan_id')->index();
            $table->uuid('content_id')->nullable()->index();
            $table->uuid('monitored_page_id')->nullable()->index();
            $table->uuid('site_competitor_id')->nullable()->index();
            $table->uuid('opportunity_id')->nullable()->index();
            $table->string('type', 80)->index();
            $table->string('status', 40)->default('active')->index();
            $table->string('review_state', 40)->default('pending')->index();
            $table->string('title', 220);
            $table->text('description')->nullable();
            $table->text('rationale')->nullable();
            $table->decimal('impact_score', 8, 2)->default(0);
            $table->decimal('urgency_score', 8, 2)->default(0);
            $table->decimal('confidence_score', 8, 2)->default(0);
            $table->string('affected_audience', 220)->nullable()->index();
            $table->string('affected_industry', 220)->nullable()->index();
            $table->string('affected_funnel_stage', 80)->nullable()->index();
            $table->text('recommended_action')->nullable();
            $table->json('source_references')->nullable();
            $table->json('source_summary')->nullable();
            $table->json('metadata_json')->nullable();
            $table->char('dedupe_hash', 64);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('promoted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('brand_growth_plan_id')->references('id')->on('brand_growth_plans')->cascadeOnDelete();
            $table->foreign('content_id')->references('id')->on('contents')->nullOnDelete();
            $table->foreign('monitored_page_id')->references('id')->on('monitored_pages')->nullOnDelete();
            $table->foreign('site_competitor_id')->references('id')->on('site_competitors')->nullOnDelete();
            $table->foreign('opportunity_id')->references('id')->on('opportunities')->nullOnDelete();
            $table->unique(['brand_growth_plan_id', 'dedupe_hash'], 'brand_growth_findings_plan_dedupe_unique');
            $table->index(['workspace_id', 'type', 'review_state'], 'brand_growth_findings_workspace_type_review_idx');
        });

        Schema::create('brand_growth_audience_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('brand_growth_plan_id')->index();
            $table->foreignId('persona_id')->nullable()->constrained('personas')->nullOnDelete();
            $table->string('proposal_type', 40)->default('audience')->index();
            $table->string('source_type', 40)->default('inferred')->index();
            $table->string('review_state', 40)->default('pending')->index();
            $table->string('name', 220);
            $table->string('role', 220)->nullable();
            $table->string('seniority', 120)->nullable();
            $table->string('department', 160)->nullable();
            $table->string('industry', 220)->nullable()->index();
            $table->string('company_size', 120)->nullable();
            $table->json('responsibilities')->nullable();
            $table->json('goals')->nullable();
            $table->json('pain_points')->nullable();
            $table->json('objections')->nullable();
            $table->json('buying_triggers')->nullable();
            $table->json('kpis')->nullable();
            $table->json('preferred_content')->nullable();
            $table->json('buying_stage_relevance')->nullable();
            $table->string('buying_committee_role', 120)->nullable()->index();
            $table->decimal('confidence_score', 8, 2)->default(0);
            $table->json('source_references')->nullable();
            $table->json('metadata_json')->nullable();
            $table->char('dedupe_hash', 64);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('brand_growth_plan_id')->references('id')->on('brand_growth_plans')->cascadeOnDelete();
            $table->unique(['brand_growth_plan_id', 'dedupe_hash'], 'brand_growth_audiences_plan_dedupe_unique');
            $table->index(['workspace_id', 'proposal_type', 'review_state'], 'brand_growth_audiences_workspace_type_review_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_growth_audience_proposals');
        Schema::dropIfExists('brand_growth_plan_findings');
        Schema::dropIfExists('brand_growth_plans');
    }
};
