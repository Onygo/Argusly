<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programmatic_clusters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('growth_program_id')->nullable()->index();
            $table->uuid('programmatic_opportunity_id')->index();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('pattern_type', 80)->index();
            $table->string('base_topic', 255);
            $table->string('variable_axis', 120)->nullable();
            $table->string('status', 40)->default('preview')->index();
            $table->unsignedInteger('estimated_assets_count')->default(0);
            $table->decimal('estimated_reach', 12, 2)->nullable();
            $table->decimal('estimated_ai_visibility', 8, 2)->nullable();
            $table->decimal('estimated_business_impact', 8, 2)->nullable();
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('growth_program_id')->references('id')->on('growth_programs')->nullOnDelete();
            $table->foreign('programmatic_opportunity_id', 'programmatic_clusters_opportunity_fk')
                ->references('id')
                ->on('programmatic_opportunities')
                ->cascadeOnDelete();
            $table->unique('programmatic_opportunity_id', 'programmatic_clusters_opportunity_unique');
            $table->index(['workspace_id', 'status', 'pattern_type'], 'programmatic_clusters_workspace_status_pattern_idx');
        });

        Schema::create('programmatic_cluster_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('programmatic_cluster_id')->index();
            $table->string('variable_value', 255);
            $table->string('title', 255);
            $table->string('slug', 255);
            $table->string('asset_type', 80);
            $table->string('intent', 80)->nullable();
            $table->decimal('priority_score', 5, 2)->nullable();
            $table->decimal('seo_score', 5, 2)->nullable();
            $table->decimal('ai_visibility_score', 5, 2)->nullable();
            $table->decimal('business_value_score', 5, 2)->nullable();
            $table->decimal('duplicate_risk_score', 5, 2)->nullable();
            $table->string('canonical_group_key', 255)->nullable()->index();
            $table->string('status', 40)->default('preview')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('programmatic_cluster_id', 'programmatic_cluster_items_cluster_fk')
                ->references('id')
                ->on('programmatic_clusters')
                ->cascadeOnDelete();
            $table->unique(['programmatic_cluster_id', 'variable_value', 'asset_type'], 'programmatic_cluster_items_unique');
            $table->index(['programmatic_cluster_id', 'status'], 'programmatic_cluster_items_cluster_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programmatic_cluster_items');
        Schema::dropIfExists('programmatic_clusters');
    }
};
