<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_themes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->string('priority', 32)->default('medium')->index();
            $table->string('market_pack_key')->nullable()->index();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->index(['workspace_id', 'status'], 'marketing_themes_workspace_status_idx');
        });

        Schema::create('marketing_objectives', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('marketing_theme_id')->nullable()->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('desired_outcome')->nullable();
            $table->string('status', 32)->default('draft')->index();
            $table->string('priority', 32)->default('medium')->index();
            $table->string('target_metric_key', 160)->nullable()->index();
            $table->decimal('target_value', 18, 4)->nullable();
            $table->decimal('current_value', 18, 4)->nullable();
            $table->string('market_pack_key')->nullable()->index();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->json('topics_json')->nullable();
            $table->json('entities_json')->nullable();
            $table->json('channels_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('marketing_theme_id')->references('id')->on('marketing_themes')->nullOnDelete();
            $table->index(['workspace_id', 'status', 'priority'], 'marketing_objectives_workspace_status_priority_idx');
        });

        Schema::create('marketing_initiatives', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('marketing_objective_id')->index();
            $table->uuid('marketing_theme_id')->nullable()->index();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('summary')->nullable();
            $table->string('status', 32)->default('planned')->index();
            $table->string('priority', 32)->default('medium')->index();
            $table->string('market_pack_key')->nullable()->index();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->json('topics_json')->nullable();
            $table->json('entities_json')->nullable();
            $table->json('channels_json')->nullable();
            $table->json('competitors_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('marketing_objective_id')->references('id')->on('marketing_objectives')->cascadeOnDelete();
            $table->foreign('marketing_theme_id')->references('id')->on('marketing_themes')->nullOnDelete();
            $table->index(['marketing_objective_id', 'status'], 'marketing_initiatives_objective_status_idx');
        });

        Schema::create('marketing_priorities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('marketing_objective_id')->nullable()->index();
            $table->uuid('marketing_initiative_id')->nullable()->index();
            $table->string('name');
            $table->string('priority_level', 32)->default('medium')->index();
            $table->unsignedTinyInteger('priority_score')->default(50)->index();
            $table->decimal('confidence_score', 8, 4)->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->json('evidence_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('marketing_objective_id')->references('id')->on('marketing_objectives')->cascadeOnDelete();
            $table->foreign('marketing_initiative_id')->references('id')->on('marketing_initiatives')->cascadeOnDelete();
            $table->index(['workspace_id', 'priority_level', 'status'], 'marketing_priorities_workspace_level_status_idx');
        });

        Schema::create('marketing_workflows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('marketing_objective_id')->nullable()->index();
            $table->uuid('marketing_initiative_id')->nullable()->index();
            $table->string('workflow_key', 120)->index();
            $table->string('name');
            $table->string('status', 32)->default('draft')->index();
            $table->string('current_stage', 120)->nullable()->index();
            $table->json('stages_json')->nullable();
            $table->json('gates_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('marketing_objective_id')->references('id')->on('marketing_objectives')->cascadeOnDelete();
            $table->foreign('marketing_initiative_id')->references('id')->on('marketing_initiatives')->cascadeOnDelete();
            $table->index(['workspace_id', 'workflow_key', 'status'], 'marketing_workflows_workspace_key_status_idx');
        });

        Schema::create('marketing_timeline_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('marketing_objective_id')->nullable()->index();
            $table->uuid('marketing_initiative_id')->nullable()->index();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at')->index();
            $table->string('event_type', 120)->index();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->string('resource_type', 120)->nullable()->index();
            $table->string('resource_id', 191)->nullable()->index();
            $table->string('resource_key', 255)->nullable()->index();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('marketing_objective_id')->references('id')->on('marketing_objectives')->cascadeOnDelete();
            $table->foreign('marketing_initiative_id')->references('id')->on('marketing_initiatives')->cascadeOnDelete();
            $table->index(['workspace_id', 'occurred_at'], 'marketing_timeline_workspace_occurred_idx');
        });

        Schema::create('marketing_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('marketing_objective_id')->nullable()->index();
            $table->uuid('marketing_initiative_id')->nullable()->index();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_type', 80)->default('operating_review')->index();
            $table->string('status', 32)->default('pending')->index();
            $table->string('decision', 80)->nullable()->index();
            $table->text('summary')->nullable();
            $table->json('evidence_json')->nullable();
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable()->index();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('marketing_objective_id')->references('id')->on('marketing_objectives')->cascadeOnDelete();
            $table->foreign('marketing_initiative_id')->references('id')->on('marketing_initiatives')->cascadeOnDelete();
            $table->index(['workspace_id', 'status', 'due_at'], 'marketing_reviews_workspace_status_due_idx');
        });

        Schema::create('marketing_operating_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('marketing_objective_id')->nullable()->index();
            $table->uuid('marketing_initiative_id')->nullable()->index();
            $table->string('relationship_type', 80)->index();
            $table->string('resource_type', 120)->index();
            $table->string('resource_id', 191)->nullable()->index();
            $table->string('resource_key', 255)->index();
            $table->string('resource_title')->nullable();
            $table->string('resource_model')->nullable();
            $table->decimal('confidence_score', 8, 4)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('marketing_objective_id')->references('id')->on('marketing_objectives')->cascadeOnDelete();
            $table->foreign('marketing_initiative_id')->references('id')->on('marketing_initiatives')->cascadeOnDelete();
            $table->unique(
                ['marketing_objective_id', 'marketing_initiative_id', 'relationship_type', 'resource_key'],
                'marketing_operating_links_unique_scope'
            );
            $table->index(['workspace_id', 'resource_type', 'relationship_type'], 'marketing_operating_links_workspace_resource_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_operating_links');
        Schema::dropIfExists('marketing_reviews');
        Schema::dropIfExists('marketing_timeline_events');
        Schema::dropIfExists('marketing_workflows');
        Schema::dropIfExists('marketing_priorities');
        Schema::dropIfExists('marketing_initiatives');
        Schema::dropIfExists('marketing_objectives');
        Schema::dropIfExists('marketing_themes');
    }
};
