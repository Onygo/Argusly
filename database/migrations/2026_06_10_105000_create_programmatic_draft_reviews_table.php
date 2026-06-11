<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programmatic_draft_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('growth_program_id')->nullable();
            $table->uuid('programmatic_draft_request_id');
            $table->uuid('draft_id');
            $table->uuid('brief_id')->nullable();
            $table->uuid('programmatic_cluster_id')->nullable();
            $table->uuid('programmatic_cluster_item_id')->nullable();
            $table->string('growth_asset_type', 80);
            $table->string('status', 40)->default('pending')->index();
            $table->decimal('overall_score', 5, 2)->default(0);
            $table->decimal('seo_score', 5, 2)->default(0);
            $table->decimal('ai_visibility_score', 5, 2)->default(0);
            $table->decimal('duplication_score', 5, 2)->default(0);
            $table->decimal('brand_fit_score', 5, 2)->default(0);
            $table->decimal('completeness_score', 5, 2)->default(0);
            $table->decimal('schema_readiness_score', 5, 2)->default(0);
            $table->decimal('internal_linking_score', 5, 2)->default(0);
            $table->decimal('risk_score', 5, 2)->default(0);
            $table->json('checks')->nullable();
            $table->json('recommendations')->nullable();
            $table->json('blocking_issues')->nullable();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('growth_program_id')->references('id')->on('growth_programs')->nullOnDelete();
            $table->foreign('programmatic_draft_request_id', 'programmatic_draft_reviews_request_fk')
                ->references('id')
                ->on('programmatic_draft_requests')
                ->cascadeOnDelete();
            $table->foreign('draft_id')->references('id')->on('drafts')->cascadeOnDelete();
            $table->foreign('brief_id')->references('id')->on('briefs')->nullOnDelete();
            $table->foreign('programmatic_cluster_id', 'programmatic_draft_reviews_cluster_fk')
                ->references('id')
                ->on('programmatic_clusters')
                ->nullOnDelete();
            $table->foreign('programmatic_cluster_item_id', 'programmatic_draft_reviews_item_fk')
                ->references('id')
                ->on('programmatic_cluster_items')
                ->nullOnDelete();
            $table->unique('programmatic_draft_request_id', 'programmatic_draft_reviews_request_unique');
            $table->index('workspace_id', 'prog_draft_rev_workspace_idx');
            $table->index('growth_program_id', 'prog_draft_rev_program_idx');
            $table->index('draft_id', 'prog_draft_rev_draft_idx');
            $table->index('brief_id', 'prog_draft_rev_brief_idx');
            $table->index('programmatic_cluster_id', 'prog_draft_rev_cluster_idx');
            $table->index('programmatic_cluster_item_id', 'prog_draft_rev_item_idx');
            $table->index('growth_asset_type', 'prog_draft_rev_asset_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programmatic_draft_reviews');
    }
};
