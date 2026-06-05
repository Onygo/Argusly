<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunity_signals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('content_id')->nullable()->index();
            $table->uuid('content_cluster_id')->nullable()->index();
            $table->uuid('campaign_id')->nullable()->index();
            $table->string('source', 64)->index();
            $table->string('category', 64)->nullable()->index();
            $table->string('topic', 220)->nullable()->index();
            $table->string('entity', 220)->nullable()->index();
            $table->decimal('signal_strength', 8, 2)->default(0);
            $table->decimal('confidence', 8, 2)->default(50);
            $table->timestamp('observed_at')->index();
            $table->json('metrics')->nullable();
            $table->json('evidence')->nullable();
            $table->json('metadata')->nullable();
            $table->char('dedupe_hash', 64)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('content_id')->references('id')->on('contents')->nullOnDelete();
            $table->foreign('content_cluster_id')->references('id')->on('content_clusters')->nullOnDelete();
            $table->foreign('campaign_id')->references('id')->on('campaigns')->nullOnDelete();
            $table->index(['workspace_id', 'source', 'observed_at'], 'opportunity_signals_workspace_source_observed_idx');
        });

        Schema::create('opportunities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('content_id')->nullable()->index();
            $table->uuid('content_cluster_id')->nullable()->index();
            $table->uuid('campaign_id')->nullable()->index();
            $table->uuid('content_opportunity_id')->nullable()->index();
            $table->uuid('agentic_marketing_opportunity_id')->nullable()->index();
            $table->string('category', 64)->index();
            $table->string('status', 40)->default('open')->index();
            $table->string('title', 220);
            $table->string('topic', 220)->nullable()->index();
            $table->text('summary')->nullable();
            $table->decimal('priority_score', 8, 2)->default(0)->index();
            $table->decimal('confidence_score', 8, 2)->default(0);
            $table->decimal('impact_score', 8, 2)->default(0);
            $table->decimal('urgency_score', 8, 2)->default(0);
            $table->decimal('effort_score', 8, 2)->default(0);
            $table->json('score_breakdown')->nullable();
            $table->json('recommended_actions')->nullable();
            $table->json('evidence')->nullable();
            $table->json('source_signal_summary')->nullable();
            $table->json('metadata')->nullable();
            $table->char('dedupe_hash', 64)->index();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('planned_at')->nullable();
            $table->timestamp('actioned_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('content_id')->references('id')->on('contents')->nullOnDelete();
            $table->foreign('content_cluster_id')->references('id')->on('content_clusters')->nullOnDelete();
            $table->foreign('campaign_id')->references('id')->on('campaigns')->nullOnDelete();
            $table->foreign('content_opportunity_id')->references('id')->on('content_opportunities')->nullOnDelete();
            $table->foreign('agentic_marketing_opportunity_id', 'opportunities_agentic_opportunity_fk')
                ->references('id')->on('agentic_marketing_opportunities')->nullOnDelete();
            $table->unique(['workspace_id', 'dedupe_hash'], 'opportunities_workspace_dedupe_unique');
            $table->index(['workspace_id', 'category', 'status', 'priority_score'], 'opportunities_workspace_category_status_priority_idx');
        });

        Schema::create('opportunity_signal_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('opportunity_id')->index();
            $table->uuid('opportunity_signal_id')->index();
            $table->decimal('weight', 8, 2)->default(1);
            $table->json('contribution')->nullable();
            $table->timestamps();

            $table->foreign('opportunity_id')->references('id')->on('opportunities')->cascadeOnDelete();
            $table->foreign('opportunity_signal_id')->references('id')->on('opportunity_signals')->cascadeOnDelete();
            $table->unique(['opportunity_id', 'opportunity_signal_id'], 'opportunity_signal_links_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_signal_links');
        Schema::dropIfExists('opportunities');
        Schema::dropIfExists('opportunity_signals');
    }
};
