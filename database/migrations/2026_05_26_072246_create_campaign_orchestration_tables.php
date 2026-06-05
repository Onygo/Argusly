<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_tone_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('brand_voice_id')->nullable()->index();
            $table->string('name', 160);
            $table->string('locale', 12)->nullable();
            $table->text('summary')->nullable();
            $table->json('voice_attributes')->nullable();
            $table->json('rules')->nullable();
            $table->json('examples')->nullable();
            $table->boolean('is_default')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('brand_voice_id')->references('id')->on('brand_voices')->nullOnDelete();
            $table->unique(['workspace_id', 'name'], 'campaign_tone_profiles_workspace_name_unique');
        });

        Schema::create('campaign_cta_presets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->string('name', 160);
            $table->string('intent', 80)->nullable();
            $table->string('label', 160)->nullable();
            $table->text('destination_url')->nullable();
            $table->text('description')->nullable();
            $table->json('rules')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_default')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unique(['workspace_id', 'name'], 'campaign_cta_presets_workspace_name_unique');
        });

        Schema::create('distribution_channels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('content_destination_id')->nullable()->index();
            $table->string('name', 160);
            $table->string('type', 48)->index();
            $table->string('provider', 80)->nullable()->index();
            $table->string('status', 32)->default('active')->index();
            $table->string('environment', 32)->default('production');
            $table->json('capabilities')->nullable();
            $table->json('planning_rules')->nullable();
            $table->json('credentials_ref')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('content_destination_id')->references('id')->on('content_destinations')->nullOnDelete();
            $table->unique(['workspace_id', 'name'], 'distribution_channels_workspace_name_unique');
            $table->index(['workspace_id', 'type', 'status'], 'distribution_channels_workspace_type_status_idx');
        });

        Schema::create('campaigns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('agentic_marketing_objective_id')->nullable()->index();
            $table->uuid('campaign_cluster_id')->nullable()->index();
            $table->uuid('tone_profile_id')->nullable()->index();
            $table->uuid('cta_preset_id')->nullable()->index();
            $table->unsignedBigInteger('owner_user_id')->nullable()->index();
            $table->string('name', 180);
            $table->string('slug', 200);
            $table->text('objective')->nullable();
            $table->string('status', 32)->default('draft')->index();
            $table->string('approval_status', 32)->default('not_required')->index();
            $table->date('planned_start_date')->nullable();
            $table->date('planned_end_date')->nullable();
            $table->timestamp('scheduled_start_at')->nullable()->index();
            $table->timestamp('scheduled_end_at')->nullable();
            $table->timestamp('submitted_for_approval_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->timestamp('last_planned_at')->nullable();
            $table->json('audience')->nullable();
            $table->json('goals')->nullable();
            $table->json('kpis')->nullable();
            $table->json('channel_mix')->nullable();
            $table->json('ai_planning_context')->nullable();
            $table->json('optimization_signals')->nullable();
            $table->json('internal_linking_strategy')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('agentic_marketing_objective_id', 'campaigns_agentic_objective_fk')
                ->references('id')->on('agentic_marketing_objectives')->nullOnDelete();
            $table->foreign('campaign_cluster_id')->references('id')->on('campaign_clusters')->nullOnDelete();
            $table->foreign('tone_profile_id')->references('id')->on('campaign_tone_profiles')->nullOnDelete();
            $table->foreign('cta_preset_id')->references('id')->on('campaign_cta_presets')->nullOnDelete();
            $table->foreign('owner_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['workspace_id', 'slug'], 'campaigns_workspace_slug_unique');
            $table->index(['workspace_id', 'status', 'planned_start_date'], 'campaigns_workspace_status_start_idx');
        });

        Schema::create('campaign_contents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id')->index();
            $table->uuid('content_id')->nullable()->index();
            $table->uuid('source_content_id')->nullable()->index();
            $table->uuid('tone_profile_id')->nullable()->index();
            $table->uuid('cta_preset_id')->nullable()->index();
            $table->string('asset_type', 48)->index();
            $table->string('status', 32)->default('planned')->index();
            $table->string('approval_status', 32)->default('not_required')->index();
            $table->unsignedInteger('sequence_order')->default(0);
            $table->string('working_title')->nullable();
            $table->string('target_locale', 12)->nullable();
            $table->timestamp('scheduled_for')->nullable()->index();
            $table->timestamp('submitted_for_approval_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->json('brief')->nullable();
            $table->json('channel_requirements')->nullable();
            $table->json('ai_generation_context')->nullable();
            $table->json('optimization_notes')->nullable();
            $table->json('internal_linking_targets')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('campaign_id')->references('id')->on('campaigns')->cascadeOnDelete();
            $table->foreign('content_id')->references('id')->on('contents')->nullOnDelete();
            $table->foreign('source_content_id')->references('id')->on('contents')->nullOnDelete();
            $table->foreign('tone_profile_id')->references('id')->on('campaign_tone_profiles')->nullOnDelete();
            $table->foreign('cta_preset_id')->references('id')->on('campaign_cta_presets')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['campaign_id', 'asset_type', 'status'], 'campaign_contents_campaign_asset_status_idx');
        });

        Schema::create('campaign_distribution_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id')->index();
            $table->uuid('campaign_content_id')->nullable()->index();
            $table->uuid('distribution_channel_id')->index();
            $table->string('asset_type', 48)->nullable()->index();
            $table->string('status', 32)->default('draft')->index();
            $table->timestamp('scheduled_for')->nullable()->index();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('distributed_at')->nullable();
            $table->json('payload')->nullable();
            $table->json('planning_notes')->nullable();
            $table->json('result')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('campaign_id')->references('id')->on('campaigns')->cascadeOnDelete();
            $table->foreign('campaign_content_id')->references('id')->on('campaign_contents')->cascadeOnDelete();
            $table->foreign('distribution_channel_id')->references('id')->on('distribution_channels')->cascadeOnDelete();
            $table->index(['campaign_id', 'status', 'scheduled_for'], 'campaign_distribution_campaign_status_schedule_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_distribution_plans');
        Schema::dropIfExists('campaign_contents');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('distribution_channels');
        Schema::dropIfExists('campaign_cta_presets');
        Schema::dropIfExists('campaign_tone_profiles');
    }
};
