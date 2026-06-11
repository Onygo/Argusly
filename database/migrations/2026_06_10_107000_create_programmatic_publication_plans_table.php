<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programmatic_publication_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('growth_program_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 40)->default('draft');
            $table->timestamp('planned_start_at')->nullable();
            $table->timestamp('planned_end_at')->nullable();
            $table->string('cadence', 40)->default('manual');
            $table->uuid('destination_id')->nullable();
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('approved_items')->default(0);
            $table->unsignedInteger('scheduled_items')->default(0);
            $table->unsignedInteger('published_items')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('workspace_id', 'prog_pub_plan_workspace_idx');
            $table->index('growth_program_id', 'prog_pub_plan_program_idx');
            $table->index('destination_id', 'prog_pub_plan_dest_idx');
            $table->index('status', 'prog_pub_plan_status_idx');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('growth_program_id')->references('id')->on('growth_programs')->nullOnDelete();
            $table->foreign('destination_id')->references('id')->on('content_destinations')->nullOnDelete();
        });

        Schema::create('programmatic_publication_plan_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('programmatic_publication_plan_id');
            $table->uuid('content_id');
            $table->uuid('publication_readiness_id');
            $table->string('growth_asset_type', 80)->nullable();
            $table->string('title');
            $table->string('slug')->nullable();
            $table->uuid('destination_id')->nullable();
            $table->timestamp('planned_publish_at')->nullable();
            $table->string('status', 40)->default('planned');
            $table->decimal('priority_score', 5, 2)->default(0);
            $table->decimal('publication_risk_score', 5, 2)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['programmatic_publication_plan_id', 'content_id'], 'prog_pub_plan_item_content_unique');
            $table->unique(['programmatic_publication_plan_id', 'publication_readiness_id'], 'prog_pub_plan_item_ready_unique');
            $table->index('workspace_id', 'prog_pub_plan_item_workspace_idx');
            $table->index('publication_readiness_id', 'prog_pub_plan_item_ready_idx');
            $table->index('destination_id', 'prog_pub_plan_item_dest_idx');
            $table->index('planned_publish_at', 'prog_pub_plan_item_date_idx');
            $table->index('status', 'prog_pub_plan_item_status_idx');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('programmatic_publication_plan_id', 'prog_pub_plan_item_plan_fk')
                ->references('id')
                ->on('programmatic_publication_plans')
                ->cascadeOnDelete();
            $table->foreign('content_id')->references('id')->on('contents')->cascadeOnDelete();
            $table->foreign('publication_readiness_id', 'prog_pub_plan_item_ready_fk')
                ->references('id')
                ->on('programmatic_publication_readiness')
                ->cascadeOnDelete();
            $table->foreign('destination_id')->references('id')->on('content_destinations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programmatic_publication_plan_items');
        Schema::dropIfExists('programmatic_publication_plans');
    }
};
