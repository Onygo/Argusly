<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programmatic_opportunities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('growth_program_id')->nullable()->index();
            $table->string('source_type', 191);
            $table->uuid('source_id');
            $table->string('pattern_type', 80)->index();
            $table->string('base_topic', 255);
            $table->string('variable_axis', 120)->nullable();
            $table->json('example_variables')->nullable();
            $table->unsignedInteger('estimated_variants_count')->nullable();
            $table->decimal('scale_score', 5, 2)->nullable();
            $table->decimal('business_value_score', 5, 2)->nullable();
            $table->decimal('seo_opportunity_score', 5, 2)->nullable();
            $table->decimal('ai_visibility_score', 5, 2)->nullable();
            $table->decimal('competition_score', 5, 2)->nullable();
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->string('status', 40)->default('detected')->index();
            $table->json('explanation')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('planned_at')->nullable();
            $table->timestamp('expanded_at')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('growth_program_id')->references('id')->on('growth_programs')->nullOnDelete();
            $table->unique(['workspace_id', 'source_type', 'source_id', 'pattern_type', 'base_topic'], 'programmatic_opportunities_dedupe_unique');
            $table->index(['source_type', 'source_id'], 'programmatic_opportunities_source_idx');
            $table->index(['workspace_id', 'status', 'scale_score'], 'programmatic_opportunities_workspace_status_score_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programmatic_opportunities');
    }
};
