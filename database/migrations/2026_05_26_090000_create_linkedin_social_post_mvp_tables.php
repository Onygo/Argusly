<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->uuid('tenant_id')->nullable()->after('id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->after('tenant_id')->index();
            $table->string('provider', 40)->default('linkedin')->after('user_id')->index();
            $table->string('provider_member_urn')->nullable()->after('provider')->index();
            $table->text('access_token')->nullable()->after('provider_member_urn');
            $table->text('refresh_token')->nullable()->after('access_token');
            $table->timestamp('expires_at')->nullable()->after('refresh_token');
            $table->json('scopes')->nullable()->after('expires_at');
        });

        Schema::create('social_posts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->nullable()->index();
            $table->uuid('campaign_id')->nullable()->index();
            $table->uuid('content_id')->nullable()->index();
            $table->uuid('social_account_id')->nullable()->index();
            $table->string('provider', 40)->default('linkedin')->index();
            $table->string('type', 40)->default('text')->index();
            $table->text('body');
            $table->text('url')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('visibility', 40)->default('public');
            $table->string('status', 40)->default('draft')->index();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('published_at')->nullable();
            $table->string('provider_post_id')->nullable()->index();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
            $table->foreign('campaign_id')->references('id')->on('campaigns')->nullOnDelete();
            $table->foreign('content_id')->references('id')->on('contents')->nullOnDelete();
            $table->foreign('social_account_id')->references('id')->on('social_accounts')->nullOnDelete();
            $table->index(['provider', 'status', 'scheduled_at'], 'social_posts_provider_status_schedule_idx');
        });

        Schema::table('social_post_variants', function (Blueprint $table): void {
            $table->uuid('social_post_id')->nullable()->after('workspace_id')->index();
            $table->string('variant_type', 80)->nullable()->after('post_type')->index();
            $table->unsignedTinyInteger('score')->nullable()->after('quality_score');
            $table->boolean('selected')->default(false)->after('score')->index();
            $table->foreign('social_post_id')->references('id')->on('social_posts')->cascadeOnDelete();
        });

        Schema::create('social_publish_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('social_post_id')->index();
            $table->string('status', 40)->index();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('attempted_at')->index();
            $table->timestamps();

            $table->foreign('social_post_id')->references('id')->on('social_posts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_publish_attempts');

        Schema::table('social_post_variants', function (Blueprint $table): void {
            $table->dropForeign(['social_post_id']);
            $table->dropColumn(['social_post_id', 'variant_type', 'score', 'selected']);
        });

        Schema::dropIfExists('social_posts');

        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->dropColumn([
                'tenant_id',
                'user_id',
                'provider',
                'provider_member_urn',
                'access_token',
                'refresh_token',
                'expires_at',
                'scopes',
            ]);
        });
    }
};
