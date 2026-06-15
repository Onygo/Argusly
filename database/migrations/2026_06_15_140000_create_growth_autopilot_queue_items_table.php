<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('growth_autopilot_queue_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->foreignId('organization_id')->nullable()->index()->constrained('organizations')->nullOnDelete();
            $table->uuid('recommended_action_id')->nullable()->index();
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable();
            $table->string('source_signature')->unique();
            $table->string('source_group', 64)->index();
            $table->string('status', 32)->default('queued')->index();
            $table->string('opportunity');
            $table->text('recommended_action');
            $table->text('expected_impact')->nullable();
            $table->unsignedTinyInteger('expected_impact_score')->default(50)->index();
            $table->unsignedTinyInteger('confidence_score')->default(50)->index();
            $table->unsignedTinyInteger('priority_score')->default(50)->index();
            $table->string('priority_label', 16)->default('medium')->index();
            $table->text('approval_requirement')->nullable();
            $table->boolean('approval_required')->default(true)->index();
            $table->json('prepared_assets')->nullable();
            $table->string('approval_cta_label')->nullable();
            $table->string('approval_cta_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('queued_at')->nullable()->index();
            $table->timestamp('approved_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('dismissed_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('recommended_action_id')->references('id')->on('recommended_actions')->nullOnDelete();
            $table->index(['workspace_id', 'status', 'priority_score'], 'growth_autopilot_workspace_status_priority_idx');
            $table->index(['source_type', 'source_id'], 'growth_autopilot_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('growth_autopilot_queue_items');
    }
};
