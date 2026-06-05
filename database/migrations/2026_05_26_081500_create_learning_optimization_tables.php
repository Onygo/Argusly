<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_learning_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('content_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->decimal('performance_score', 8, 2)->default(0)->index();
            $table->decimal('article_score', 8, 2)->default(0);
            $table->decimal('linkedin_score', 8, 2)->default(0);
            $table->decimal('ai_visibility_score', 8, 2)->default(0);
            $table->decimal('conversion_score', 8, 2)->default(0);
            $table->decimal('cta_score', 8, 2)->default(0);
            $table->decimal('hook_score', 8, 2)->default(0);
            $table->decimal('tone_score', 8, 2)->default(0);
            $table->decimal('topic_score', 8, 2)->default(0);
            $table->string('primary_topic')->nullable()->index();
            $table->json('hook_analysis')->nullable();
            $table->json('cta_analysis')->nullable();
            $table->json('tone_analysis')->nullable();
            $table->json('topic_analysis')->nullable();
            $table->json('ai_visibility_trend')->nullable();
            $table->json('historical_trends')->nullable();
            $table->json('score_breakdown')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamp('analyzed_at')->index();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('content_id')->references('id')->on('contents')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->unique(['content_id'], 'content_learning_profiles_content_unique');
            $table->index(['workspace_id', 'performance_score'], 'content_learning_profiles_workspace_score_idx');
        });

        Schema::create('campaign_learning_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('campaign_id')->index();
            $table->decimal('performance_score', 8, 2)->default(0)->index();
            $table->decimal('content_score', 8, 2)->default(0);
            $table->decimal('distribution_score', 8, 2)->default(0);
            $table->decimal('ai_visibility_score', 8, 2)->default(0);
            $table->decimal('conversion_score', 8, 2)->default(0);
            $table->json('content_mix_analysis')->nullable();
            $table->json('channel_analysis')->nullable();
            $table->json('tone_analysis')->nullable();
            $table->json('topic_analysis')->nullable();
            $table->json('historical_trends')->nullable();
            $table->json('score_breakdown')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamp('analyzed_at')->index();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('campaign_id')->references('id')->on('campaigns')->cascadeOnDelete();
            $table->unique(['campaign_id'], 'campaign_learning_profiles_campaign_unique');
            $table->index(['workspace_id', 'performance_score'], 'campaign_learning_profiles_workspace_score_idx');
        });

        Schema::create('learning_recommendations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('content_id')->nullable()->index();
            $table->uuid('campaign_id')->nullable()->index();
            $table->uuid('content_learning_profile_id')->nullable()->index();
            $table->uuid('campaign_learning_profile_id')->nullable()->index();
            $table->string('type', 64)->index();
            $table->string('status', 32)->default('proposed')->index();
            $table->decimal('priority_score', 8, 2)->default(0)->index();
            $table->decimal('confidence_score', 8, 2)->default(0);
            $table->string('title');
            $table->text('summary')->nullable();
            $table->json('recommended_actions')->nullable();
            $table->json('explanation')->nullable();
            $table->json('evidence')->nullable();
            $table->json('expected_impact')->nullable();
            $table->timestamp('recommended_at')->index();
            $table->timestamp('actioned_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('content_id')->references('id')->on('contents')->nullOnDelete();
            $table->foreign('campaign_id')->references('id')->on('campaigns')->nullOnDelete();
            $table->foreign('content_learning_profile_id', 'learning_rec_content_profile_fk')
                ->references('id')->on('content_learning_profiles')->nullOnDelete();
            $table->foreign('campaign_learning_profile_id', 'learning_rec_campaign_profile_fk')
                ->references('id')->on('campaign_learning_profiles')->nullOnDelete();
            $table->index(['workspace_id', 'type', 'status', 'priority_score'], 'learning_recs_workspace_type_status_priority_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_recommendations');
        Schema::dropIfExists('campaign_learning_profiles');
        Schema::dropIfExists('content_learning_profiles');
    }
};
