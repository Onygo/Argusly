<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_clusters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('name', 191);
            $table->string('topic_keyword', 191);
            $table->uuid('pillar_content_id')->nullable();
            $table->json('supporting_content_ids')->nullable();
            $table->decimal('cluster_score', 5, 2)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'topic_keyword'], 'content_clusters_workspace_topic_idx');
            $table->index(['workspace_id', 'cluster_score'], 'content_clusters_workspace_score_idx');
            $table->index(['workspace_id', 'pillar_content_id'], 'content_clusters_workspace_pillar_idx');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('pillar_content_id')->references('id')->on('contents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_clusters');
    }
};
