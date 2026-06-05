<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_opportunity_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->uuid('workspace_id');
            $table->uuid('client_site_id')->nullable();
            $table->string('status', 40)->default('queued')->index();
            $table->string('source_type', 80)->default('agentic_marketing');
            $table->json('input')->nullable();
            $table->json('result')->nullable();
            $table->unsignedInteger('candidates_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('refreshed_count')->default(0);
            $table->text('failure_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->index(['workspace_id', 'created_at'], 'content_opp_runs_workspace_created_idx');
        });

        Schema::create('content_opportunities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->uuid('workspace_id');
            $table->uuid('client_site_id')->nullable();
            $table->uuid('content_opportunity_run_id')->nullable();
            $table->string('type', 80);
            $table->string('status', 40)->default('open')->index();
            $table->string('freshness_status', 40)->default('fresh')->index();
            $table->string('title', 255);
            $table->text('reasoning')->nullable();
            $table->text('why_this_matters')->nullable();
            $table->text('why_now')->nullable();
            $table->text('competitor_pressure')->nullable();
            $table->text('ai_visibility_opportunity')->nullable();
            $table->string('target_audience', 191)->nullable();
            $table->string('funnel_stage', 40)->nullable();
            $table->string('primary_search_intent', 80)->nullable();
            $table->text('angle')->nullable();
            $table->string('expected_impact', 40)->default('medium');
            $table->decimal('confidence_score', 5, 2)->default(0);
            $table->decimal('urgency_score', 5, 2)->default(0);
            $table->decimal('business_value_score', 5, 2)->default(0);
            $table->decimal('priority_score', 5, 2)->default(0);
            $table->json('related_entities')->nullable();
            $table->json('supporting_existing_content')->nullable();
            $table->json('recommended_internal_links')->nullable();
            $table->json('localization_recommendation')->nullable();
            $table->string('suggested_cta', 191)->nullable();
            $table->string('suggested_schema', 80)->nullable();
            $table->json('source_signals')->nullable();
            $table->json('query_intent_payload')->nullable();
            $table->json('normalized_payload')->nullable();
            $table->string('dedupe_hash', 64);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('stale_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'dedupe_hash'], 'content_opportunities_workspace_dedupe_unique');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('content_opportunity_run_id', 'content_opportunities_run_fk')
                ->references('id')
                ->on('content_opportunity_runs')
                ->nullOnDelete();
            $table->index(['workspace_id', 'status', 'priority_score'], 'content_opportunities_priority_idx');
            $table->index(['workspace_id', 'type', 'funnel_stage'], 'content_opportunities_type_stage_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_opportunities');
        Schema::dropIfExists('content_opportunity_runs');
    }
};
