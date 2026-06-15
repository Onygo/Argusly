<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommended_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->foreignId('organization_id')->nullable()->index()->constrained('organizations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable();
            $table->string('source_signature')->unique();
            $table->string('source_group', 64)->index();
            $table->string('action_type', 64)->index();
            $table->string('status', 32)->default('open')->index();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->text('why_this_matters')->nullable();
            $table->text('expected_outcome')->nullable();
            $table->text('what_argusly_will_do')->nullable();
            $table->text('what_requires_approval')->nullable();
            $table->string('estimated_effort', 32)->default('medium')->index();
            $table->unsignedTinyInteger('priority_score')->default(50)->index();
            $table->unsignedTinyInteger('confidence_score')->default(50)->index();
            $table->unsignedTinyInteger('expected_impact_score')->default(50)->index();
            $table->string('priority_label', 16)->default('medium')->index();
            $table->string('confidence_label', 16)->default('medium');
            $table->string('expected_impact_label', 16)->default('medium');
            $table->string('primary_cta_label')->nullable();
            $table->string('primary_cta_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('visible_at')->nullable()->index();
            $table->timestamp('approved_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('dismissed_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->index(['workspace_id', 'status', 'priority_score'], 'recommended_actions_workspace_status_priority_idx');
            $table->index(['workspace_id', 'source_group', 'created_at'], 'recommended_actions_workspace_source_created_idx');
            $table->index(['source_type', 'source_id'], 'recommended_actions_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommended_actions');
    }
};
