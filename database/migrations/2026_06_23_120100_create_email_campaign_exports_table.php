<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_campaign_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('campaign_id');
            $table->uuid('campaign_content_id');
            $table->uuid('email_marketing_connection_id');
            $table->string('provider', 40)->index();
            $table->string('status', 32)->default('pending')->index();
            $table->string('remote_campaign_id', 255)->nullable();
            $table->string('remote_template_id', 255)->nullable();
            $table->string('remote_url', 2048)->nullable();
            $table->string('idempotency_key', 255)->nullable();
            $table->json('payload')->nullable();
            $table->json('provider_response')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status', 'created_at'], 'email_exports_workspace_status_idx');
            $table->index(['campaign_id', 'created_at'], 'email_exports_campaign_created_idx');
            $table->index(['campaign_content_id', 'created_at'], 'email_exports_asset_created_idx');
            $table->index(['email_marketing_connection_id', 'created_at'], 'email_exports_connection_created_idx');
            $table->unique(['email_marketing_connection_id', 'campaign_content_id'], 'email_exports_connection_asset_unique');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('campaign_id')->references('id')->on('campaigns')->cascadeOnDelete();
            $table->foreign('campaign_content_id')->references('id')->on('campaign_contents')->cascadeOnDelete();
            $table->foreign('email_marketing_connection_id', 'email_exports_connection_fk')
                ->references('id')
                ->on('email_marketing_connections')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaign_exports');
    }
};
