<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programmatic_brief_blueprints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('growth_program_id')->nullable()->index();
            $table->uuid('programmatic_cluster_id')->index();
            $table->uuid('programmatic_cluster_item_id')->index();
            $table->string('growth_asset_type', 80)->index();
            $table->string('title', 255);
            $table->string('slug', 255);
            $table->string('intent', 80)->nullable();
            $table->string('audience', 255)->nullable();
            $table->string('primary_keyword', 255)->nullable();
            $table->json('secondary_keywords')->nullable();
            $table->json('outline')->nullable();
            $table->json('required_sections')->nullable();
            $table->json('faq_questions')->nullable();
            $table->json('schema_recommendations')->nullable();
            $table->json('internal_linking_plan')->nullable();
            $table->string('cta_recommendation', 160)->nullable();
            $table->json('seo_requirements')->nullable();
            $table->json('ai_visibility_requirements')->nullable();
            $table->json('quality_requirements')->nullable();
            $table->string('status', 40)->default('draft')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('growth_program_id')->references('id')->on('growth_programs')->nullOnDelete();
            $table->foreign('programmatic_cluster_id', 'programmatic_blueprints_cluster_fk')
                ->references('id')
                ->on('programmatic_clusters')
                ->cascadeOnDelete();
            $table->foreign('programmatic_cluster_item_id', 'programmatic_blueprints_item_fk')
                ->references('id')
                ->on('programmatic_cluster_items')
                ->cascadeOnDelete();
            $table->unique('programmatic_cluster_item_id', 'programmatic_blueprints_item_unique');
            $table->index(['workspace_id', 'status', 'growth_asset_type'], 'programmatic_blueprints_workspace_status_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programmatic_brief_blueprints');
    }
};
