<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agentic_marketing_execution_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->uuid('workspace_id');
            $table->uuid('brand_voice_id')->nullable();
            $table->string('agentic_execution_mode', 32)->default('guided');
            $table->boolean('autonomous_publication_enabled')->default(false);
            $table->boolean('autonomous_refresh_enabled')->default(false);
            $table->boolean('autonomous_internal_linking_enabled')->default(false);
            $table->boolean('autonomous_brief_generation_enabled')->default(false);
            $table->boolean('autonomous_chained_plans_enabled')->default(false);
            $table->unsignedSmallInteger('max_autonomous_actions_per_day')->default(3);
            $table->unsignedInteger('max_autonomous_credits_per_month')->default(100);
            $table->unsignedTinyInteger('require_approval_above_priority_score')->default(80);
            $table->boolean('require_approval_for_new_pages')->default(true);
            $table->boolean('require_approval_for_external_publication')->default(true);
            $table->json('allowed_site_ids')->nullable();
            $table->json('allowed_publishing_destination_ids')->nullable();
            $table->boolean('notification_email_enabled')->default(true);
            $table->timestamp('last_autonomous_action_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('brand_voice_id')->references('id')->on('brand_voices')->cascadeOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['workspace_id', 'brand_voice_id'], 'agentic_exec_settings_workspace_brand_unique');
            $table->index(['organization_id', 'agentic_execution_mode'], 'agentic_exec_settings_org_mode_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agentic_marketing_execution_settings');
    }
};
