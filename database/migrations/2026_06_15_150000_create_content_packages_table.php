<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_packages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->foreignId('organization_id')->nullable()->index()->constrained('organizations')->nullOnDelete();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('growth_autopilot_queue_item_id')->nullable()->index();
            $table->uuid('recommended_action_id')->nullable()->index();
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable();
            $table->string('source_signature')->unique();
            $table->string('status', 32)->default('prepared')->index();
            $table->string('title');
            $table->text('opportunity_summary')->nullable();
            $table->text('recommended_action')->nullable();
            $table->uuid('brief_id')->nullable()->index();
            $table->uuid('draft_id')->nullable()->index();
            $table->uuid('linkedin_variant_id')->nullable()->index();
            $table->json('cta_recommendation')->nullable();
            $table->json('internal_linking_suggestions')->nullable();
            $table->json('publishing_checklist')->nullable();
            $table->json('prepared_assets')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('prepared_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('growth_autopilot_queue_item_id')->references('id')->on('growth_autopilot_queue_items')->nullOnDelete();
            $table->foreign('recommended_action_id')->references('id')->on('recommended_actions')->nullOnDelete();
            $table->foreign('brief_id')->references('id')->on('briefs')->nullOnDelete();
            $table->foreign('draft_id')->references('id')->on('drafts')->nullOnDelete();
            $table->foreign('linkedin_variant_id')->references('id')->on('social_post_variants')->nullOnDelete();
            $table->index(['workspace_id', 'status', 'prepared_at'], 'content_packages_workspace_status_prepared_idx');
            $table->index(['source_type', 'source_id'], 'content_packages_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_packages');
    }
};
