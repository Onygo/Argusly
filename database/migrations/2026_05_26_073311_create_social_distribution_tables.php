<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('distribution_channel_id')->nullable()->index();
            $table->string('platform', 40)->index();
            $table->string('account_type', 40)->default('organization');
            $table->string('display_name', 180);
            $table->string('platform_account_id', 180)->nullable();
            $table->string('status', 40)->default('draft')->index();
            $table->json('oauth')->nullable();
            $table->json('token_ref')->nullable();
            $table->json('profile')->nullable();
            $table->json('publishing_rules')->nullable();
            $table->json('rate_limit_policy')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('rate_limited_until')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('distribution_channel_id')->references('id')->on('distribution_channels')->nullOnDelete();
            $table->unique(['workspace_id', 'platform', 'platform_account_id'], 'social_accounts_workspace_platform_remote_unique');
            $table->index(['workspace_id', 'platform', 'status'], 'social_accounts_workspace_platform_status_idx');
        });

        Schema::create('social_post_variants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('campaign_id')->nullable()->index();
            $table->uuid('campaign_content_id')->nullable()->index();
            $table->uuid('campaign_distribution_plan_id')->nullable()->index();
            $table->uuid('content_id')->nullable()->index();
            $table->uuid('social_account_id')->nullable()->index();
            $table->string('platform', 40)->index();
            $table->string('post_type', 64)->index();
            $table->string('status', 40)->default('generation_requested')->index();
            $table->unsignedInteger('variant_number')->default(1);
            $table->string('hook')->nullable();
            $table->text('body')->nullable();
            $table->json('hashtags')->nullable();
            $table->json('mentions')->nullable();
            $table->json('media_refs')->nullable();
            $table->json('generation_prompt_context')->nullable();
            $table->json('generation_result')->nullable();
            $table->string('generation_model')->nullable();
            $table->unsignedInteger('estimated_character_count')->nullable();
            $table->unsignedTinyInteger('quality_score')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('submitted_for_approval_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->text('approval_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('campaign_id')->references('id')->on('campaigns')->nullOnDelete();
            $table->foreign('campaign_content_id')->references('id')->on('campaign_contents')->nullOnDelete();
            $table->foreign('campaign_distribution_plan_id', 'social_variants_distribution_plan_fk')
                ->references('id')->on('campaign_distribution_plans')->nullOnDelete();
            $table->foreign('content_id')->references('id')->on('contents')->nullOnDelete();
            $table->foreign('social_account_id')->references('id')->on('social_accounts')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['workspace_id', 'platform', 'status'], 'social_variants_workspace_platform_status_idx');
            $table->index(['campaign_id', 'post_type', 'status'], 'social_variants_campaign_type_status_idx');
        });

        Schema::create('social_publications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('social_account_id')->index();
            $table->uuid('social_post_variant_id')->index();
            $table->uuid('campaign_id')->nullable()->index();
            $table->uuid('campaign_distribution_plan_id')->nullable()->index();
            $table->string('platform', 40)->index();
            $table->string('status', 40)->default('draft')->index();
            $table->timestamp('scheduled_for')->nullable()->index();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('remote_post_id')->nullable();
            $table->text('remote_url')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_retry_at')->nullable()->index();
            $table->string('last_error_code', 80)->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamp('rate_limited_until')->nullable()->index();
            $table->json('payload_snapshot')->nullable();
            $table->json('response_snapshot')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('social_account_id')->references('id')->on('social_accounts')->cascadeOnDelete();
            $table->foreign('social_post_variant_id')->references('id')->on('social_post_variants')->cascadeOnDelete();
            $table->foreign('campaign_id')->references('id')->on('campaigns')->nullOnDelete();
            $table->foreign('campaign_distribution_plan_id', 'social_publications_distribution_plan_fk')
                ->references('id')->on('campaign_distribution_plans')->nullOnDelete();
            $table->index(['workspace_id', 'status', 'scheduled_for'], 'social_publications_workspace_status_schedule_idx');
        });

        Schema::create('social_engagement_metrics', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('social_account_id')->index();
            $table->uuid('social_publication_id')->index();
            $table->string('platform', 40)->index();
            $table->timestamp('measured_at')->index();
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('reach')->default(0);
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->unsignedBigInteger('shares')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('follows')->default(0);
            $table->decimal('engagement_rate', 8, 4)->nullable();
            $table->json('raw_metrics')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('social_account_id')->references('id')->on('social_accounts')->cascadeOnDelete();
            $table->foreign('social_publication_id')->references('id')->on('social_publications')->cascadeOnDelete();
            $table->index(['social_publication_id', 'measured_at'], 'social_metrics_publication_measured_idx');
        });

        Schema::create('social_rate_limit_windows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('social_account_id')->nullable()->index();
            $table->string('platform', 40)->index();
            $table->string('bucket', 80)->default('publish')->index();
            $table->unsignedInteger('request_limit')->nullable();
            $table->unsignedInteger('remaining')->nullable();
            $table->timestamp('resets_at')->nullable()->index();
            $table->timestamp('limited_until')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('social_account_id')->references('id')->on('social_accounts')->cascadeOnDelete();
            $table->index(['platform', 'bucket', 'limited_until'], 'social_rate_limits_platform_bucket_until_idx');
        });

        Schema::create('social_repost_suggestions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('social_publication_id')->index();
            $table->uuid('campaign_id')->nullable()->index();
            $table->string('platform', 40)->index();
            $table->string('status', 40)->default('proposed')->index();
            $table->timestamp('suggested_for')->nullable()->index();
            $table->string('reason_code', 80)->nullable();
            $table->text('reason')->nullable();
            $table->json('suggested_angle')->nullable();
            $table->json('performance_snapshot')->nullable();
            $table->uuid('created_variant_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('social_publication_id')->references('id')->on('social_publications')->cascadeOnDelete();
            $table->foreign('campaign_id')->references('id')->on('campaigns')->nullOnDelete();
            $table->foreign('created_variant_id')->references('id')->on('social_post_variants')->nullOnDelete();
        });

        Schema::create('social_distribution_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->nullable()->index();
            $table->uuid('social_account_id')->nullable()->index();
            $table->uuid('social_post_variant_id')->nullable()->index();
            $table->uuid('social_publication_id')->nullable()->index();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('event', 120)->index();
            $table->string('subject_type', 120);
            $table->string('subject_id', 64);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
            $table->foreign('social_account_id')->references('id')->on('social_accounts')->nullOnDelete();
            $table->foreign('social_post_variant_id')->references('id')->on('social_post_variants')->nullOnDelete();
            $table->foreign('social_publication_id')->references('id')->on('social_publications')->nullOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['workspace_id', 'created_at'], 'social_audit_workspace_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_distribution_audit_logs');
        Schema::dropIfExists('social_repost_suggestions');
        Schema::dropIfExists('social_rate_limit_windows');
        Schema::dropIfExists('social_engagement_metrics');
        Schema::dropIfExists('social_publications');
        Schema::dropIfExists('social_post_variants');
        Schema::dropIfExists('social_accounts');
    }
};
