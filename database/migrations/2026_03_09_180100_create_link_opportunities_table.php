<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_opportunities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('source_content_id');
            $table->uuid('target_content_id');
            $table->string('anchor_text_suggestion', 255)->nullable();
            $table->text('context_snippet')->nullable();
            $table->string('status', 32)->default('suggested');
            $table->decimal('relevance_score', 5, 2)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status'], 'link_opps_workspace_status_idx');
            $table->index(['workspace_id', 'source_content_id'], 'link_opps_workspace_source_idx');
            $table->index(['workspace_id', 'target_content_id'], 'link_opps_workspace_target_idx');
            $table->unique(['workspace_id', 'source_content_id', 'target_content_id'], 'link_opps_workspace_source_target_unq');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('source_content_id')->references('id')->on('contents')->cascadeOnDelete();
            $table->foreign('target_content_id')->references('id')->on('contents')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_opportunities');
    }
};
