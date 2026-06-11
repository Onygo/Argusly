<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunity_execution_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('opportunity_id')->index();
            $table->string('status', 40)->default('draft')->index();
            $table->string('title', 220);
            $table->text('summary')->nullable();
            $table->text('objective')->nullable();
            $table->string('recommended_channel', 80)->nullable();
            $table->string('recommended_format', 120)->nullable();
            $table->decimal('priority_score', 8, 2)->default(0);
            $table->decimal('estimated_effort', 8, 2)->default(0);
            $table->decimal('expected_impact', 8, 2)->default(0);
            $table->json('planned_steps')->nullable();
            $table->json('source_evidence')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('opportunity_id')->references('id')->on('opportunities')->cascadeOnDelete();
            $table->index(['workspace_id', 'opportunity_id', 'status'], 'execution_plans_workspace_opportunity_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_execution_plans');
    }
};
