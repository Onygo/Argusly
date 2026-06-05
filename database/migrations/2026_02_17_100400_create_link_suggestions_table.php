<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('link_suggestions')) {
            return;
        }

        Schema::create('link_suggestions', function (Blueprint $table) {
            $table->id();
            $table->uuid('source_article_id');
            $table->uuid('target_article_id');
            $table->uuid('source_workspace_id');
            $table->uuid('target_workspace_id');
            $table->uuid('source_client_site_id');
            $table->uuid('target_client_site_id');
            $table->decimal('similarity_score', 4, 2);
            $table->json('shared_entities')->nullable();
            $table->decimal('intent_match_score', 4, 2)->default(0.00);
            $table->decimal('audience_overlap_score', 4, 2)->default(0.00);
            $table->json('suggested_anchor_variants')->nullable();
            $table->enum('suggested_placement', ['inline', 'footnote'])->default('inline');
            $table->enum('status', ['draft', 'suggested', 'approved', 'rejected', 'applied'])->default('draft');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['source_article_id', 'status']);
            $table->index(['target_workspace_id']);
            $table->index(
                ['source_workspace_id', 'target_workspace_id', 'created_at'],
                'ls_src_tgt_created_idx'
            );

            $table->foreign('source_article_id')->references('id')->on('drafts')->cascadeOnDelete();
            $table->foreign('target_article_id')->references('id')->on('drafts')->cascadeOnDelete();
            $table->foreign('source_workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('target_workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('source_client_site_id')->references('id')->on('client_sites')->cascadeOnDelete();
            $table->foreign('target_client_site_id')->references('id')->on('client_sites')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_suggestions');
    }
};
