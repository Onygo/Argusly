<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programmatic_publication_readiness', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('growth_program_id')->nullable();
            $table->uuid('content_id');
            $table->uuid('programmatic_draft_review_id')->nullable();
            $table->uuid('programmatic_draft_request_id')->nullable();
            $table->uuid('programmatic_cluster_id')->nullable();
            $table->uuid('programmatic_cluster_item_id')->nullable();
            $table->string('growth_asset_type', 80)->nullable();
            $table->string('status', 40)->default('pending');
            $table->decimal('readiness_score', 5, 2)->default(0);
            $table->decimal('seo_score', 5, 2)->default(0);
            $table->decimal('schema_score', 5, 2)->default(0);
            $table->decimal('internal_linking_score', 5, 2)->default(0);
            $table->decimal('publication_risk_score', 5, 2)->default(0);
            $table->decimal('destination_readiness_score', 5, 2)->default(0);
            $table->json('checks')->nullable();
            $table->json('missing_requirements')->nullable();
            $table->json('recommendations')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('content_id', 'prog_pub_ready_content_unique');
            $table->index('workspace_id', 'prog_pub_ready_workspace_idx');
            $table->index('growth_program_id', 'prog_pub_ready_program_idx');
            $table->index('programmatic_draft_review_id', 'prog_pub_ready_review_idx');
            $table->index('programmatic_cluster_id', 'prog_pub_ready_cluster_idx');
            $table->index('programmatic_cluster_item_id', 'prog_pub_ready_item_idx');
            $table->index('status', 'prog_pub_ready_status_idx');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('growth_program_id')->references('id')->on('growth_programs')->nullOnDelete();
            $table->foreign('content_id')->references('id')->on('contents')->cascadeOnDelete();
            $table->foreign('programmatic_draft_review_id', 'prog_pub_ready_review_fk')
                ->references('id')
                ->on('programmatic_draft_reviews')
                ->nullOnDelete();
            $table->foreign('programmatic_draft_request_id', 'prog_pub_ready_request_fk')
                ->references('id')
                ->on('programmatic_draft_requests')
                ->nullOnDelete();
            $table->foreign('programmatic_cluster_id', 'prog_pub_ready_cluster_fk')
                ->references('id')
                ->on('programmatic_clusters')
                ->nullOnDelete();
            $table->foreign('programmatic_cluster_item_id', 'prog_pub_ready_item_fk')
                ->references('id')
                ->on('programmatic_cluster_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programmatic_publication_readiness');
    }
};
