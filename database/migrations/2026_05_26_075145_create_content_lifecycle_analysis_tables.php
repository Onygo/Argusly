<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_lifecycle_analyses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('content_id')->index();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->decimal('lifecycle_score', 8, 2)->default(0);
            $table->decimal('decay_score', 8, 2)->default(0)->index();
            $table->string('decay_risk_level', 32)->index();
            $table->decimal('refresh_priority_score', 8, 2)->default(0)->index();
            $table->decimal('confidence_score', 8, 2)->default(0);
            $table->json('signals')->nullable();
            $table->json('score_breakdown')->nullable();
            $table->json('refresh_recommendations')->nullable();
            $table->json('campaign_reconnect_suggestions')->nullable();
            $table->json('related_content_suggestions')->nullable();
            $table->json('internal_linking_suggestions')->nullable();
            $table->timestamp('analyzed_at')->index();
            $table->timestamps();

            $table->foreign('content_id')->references('id')->on('contents')->cascadeOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->index(['workspace_id', 'decay_risk_level', 'refresh_priority_score'], 'content_lifecycle_analysis_ws_risk_priority_idx');
        });

        Schema::create('content_refresh_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('content_id')->index();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('content_lifecycle_analysis_id')->nullable()->index();
            $table->uuid('campaign_id')->nullable()->index();
            $table->string('type', 64)->index();
            $table->string('status', 32)->default('open')->index();
            $table->unsignedTinyInteger('priority')->default(50)->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('recommended_actions')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('content_id')->references('id')->on('contents')->cascadeOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('content_lifecycle_analysis_id', 'content_refresh_tasks_analysis_fk')
                ->references('id')->on('content_lifecycle_analyses')->nullOnDelete();
            $table->foreign('campaign_id')->references('id')->on('campaigns')->nullOnDelete();
            $table->index(['workspace_id', 'status', 'priority'], 'content_refresh_tasks_ws_status_priority_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_refresh_tasks');
        Schema::dropIfExists('content_lifecycle_analyses');
    }
};
