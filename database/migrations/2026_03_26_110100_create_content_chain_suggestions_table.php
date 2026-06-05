<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_chain_suggestions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('source_content_id');
            $table->uuid('target_content_id')->nullable();
            $table->uuid('content_cluster_id')->nullable();
            $table->string('fingerprint', 120);
            $table->string('suggestion_kind', 32);
            $table->string('suggestion_type', 64);
            $table->string('status', 32)->default('suggested');
            $table->string('title', 255)->nullable();
            $table->string('goal_type', 64)->nullable();
            $table->string('anchor_text', 191)->nullable();
            $table->string('placement_type', 32)->nullable();
            $table->string('placement_label', 191)->nullable();
            $table->text('rationale')->nullable();
            $table->decimal('score', 6, 2)->nullable();
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->json('score_breakdown')->nullable();
            $table->json('source_snapshot')->nullable();
            $table->json('placement_meta')->nullable();
            $table->json('meta')->nullable();
            $table->uuid('generated_content_id')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'fingerprint'], 'content_chain_suggestions_workspace_fingerprint_unq');
            $table->index(['workspace_id', 'source_content_id', 'suggestion_kind'], 'content_chain_suggestions_source_kind_idx');
            $table->index(['workspace_id', 'status'], 'content_chain_suggestions_workspace_status_idx');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('source_content_id')->references('id')->on('contents')->cascadeOnDelete();
            $table->foreign('target_content_id')->references('id')->on('contents')->nullOnDelete();
            $table->foreign('content_cluster_id')->references('id')->on('content_clusters')->nullOnDelete();
            $table->foreign('generated_content_id')->references('id')->on('contents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_chain_suggestions');
    }
};
