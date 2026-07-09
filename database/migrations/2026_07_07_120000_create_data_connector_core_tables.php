<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_providers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider_key', 120)->unique();
            $table->string('name', 160);
            $table->string('category', 40)->default('other')->index();
            $table->string('status', 40)->default('active')->index();
            $table->json('config_json')->nullable();
            $table->boolean('supports_oauth')->default(false);
            $table->boolean('supports_sync')->default(false);
            $table->boolean('supports_webhooks')->default(false);
            $table->timestamps();
        });

        Schema::create('connector_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('connector_provider_id')->index();
            $table->string('provider_key', 120)->index();
            $table->string('account_name', 180);
            $table->string('external_account_id', 191)->nullable()->index();
            $table->string('status', 40)->default('draft')->index();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamp('last_synced_at')->nullable()->index();
            $table->string('health_status', 40)->nullable()->index();
            $table->string('health_severity', 40)->nullable()->index();
            $table->uuid('latest_health_event_id')->nullable()->index();
            $table->timestamp('health_checked_at')->nullable()->index();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('connector_provider_id')->references('id')->on('connector_providers')->cascadeOnDelete();
            $table->index(['workspace_id', 'provider_key', 'status'], 'connector_accounts_workspace_provider_status_idx');
        });

        Schema::create('connector_credentials', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->nullable()->index();
            $table->uuid('connector_provider_id')->index();
            $table->string('credential_type', 40)->index();
            $table->string('name', 180);
            $table->text('encrypted_config')->nullable();
            $table->string('status', 40)->default('active')->index();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
            $table->foreign('connector_provider_id')->references('id')->on('connector_providers')->cascadeOnDelete();
            $table->index(['workspace_id', 'connector_provider_id', 'credential_type'], 'connector_credentials_workspace_provider_type_idx');
        });

        Schema::create('connector_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('connector_account_id')->index();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->string('token_type', 60)->default('Bearer');
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->json('rotation_metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('connector_account_id')->references('id')->on('connector_accounts')->cascadeOnDelete();
        });

        Schema::create('connector_oauth_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('state_hash', 64)->unique();
            $table->string('nonce_hash', 64);
            $table->uuid('workspace_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->index();
            $table->uuid('connector_provider_id')->nullable()->index();
            $table->uuid('connector_account_id')->nullable()->index();
            $table->string('provider_key', 120)->index();
            $table->string('redirect_uri', 2048)->nullable();
            $table->json('scopes_json')->nullable();
            $table->text('pkce_code_verifier');
            $table->string('pkce_code_challenge', 128);
            $table->string('pkce_code_challenge_method', 20)->default('S256');
            $table->json('context_json')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('connector_provider_id')->references('id')->on('connector_providers')->nullOnDelete();
            $table->foreign('connector_account_id')->references('id')->on('connector_accounts')->nullOnDelete();
            $table->index(['provider_key', 'expires_at'], 'connector_oauth_states_provider_expires_idx');
        });

        Schema::create('connector_scopes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('connector_account_id')->index();
            $table->string('scope', 255);
            $table->string('scope_type', 40)->default('granted')->index();
            $table->string('consent_status', 40)->default('pending')->index();
            $table->timestamp('granted_at')->nullable();
            $table->timestamps();

            $table->foreign('connector_account_id')->references('id')->on('connector_accounts')->cascadeOnDelete();
            $table->unique(['connector_account_id', 'scope', 'scope_type'], 'connector_scopes_account_scope_type_unique');
        });

        Schema::create('connector_datasets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('connector_account_id')->index();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->string('provider_key', 120)->index();
            $table->string('dataset_key', 160);
            $table->string('dataset_type', 80)->index();
            $table->string('external_dataset_id', 191)->nullable()->index();
            $table->string('display_name', 220);
            $table->string('status', 40)->default('active')->index();
            $table->string('sync_frequency', 80)->nullable();
            $table->timestamp('next_sync_at')->nullable()->index();
            $table->timestamp('last_sync_at')->nullable()->index();
            $table->timestamp('discovered_at')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('deactivated_at')->nullable()->index();
            $table->string('health_status', 40)->nullable()->index();
            $table->string('health_severity', 40)->nullable()->index();
            $table->uuid('latest_health_event_id')->nullable()->index();
            $table->timestamp('health_checked_at')->nullable()->index();
            $table->json('cursor_json')->nullable();
            $table->json('capabilities_json')->nullable();
            $table->json('sync_config_json')->nullable();
            $table->json('config_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('connector_account_id')->references('id')->on('connector_accounts')->cascadeOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->unique(['connector_account_id', 'dataset_key'], 'connector_datasets_account_dataset_unique');
            $table->index(['workspace_id', 'provider_key', 'status'], 'connector_datasets_workspace_provider_status_idx');
            $table->index(['connector_account_id', 'status', 'last_seen_at'], 'connector_datasets_account_status_seen_idx');
        });

        Schema::create('connector_sync_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('connector_account_id')->index();
            $table->uuid('connector_dataset_id')->nullable()->index();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->string('provider_key', 120)->index();
            $table->string('dataset_key', 160)->nullable()->index();
            $table->string('status', 40)->default('pending')->index();
            $table->string('run_type', 40)->default('manual')->index();
            $table->timestamp('window_start')->nullable();
            $table->timestamp('window_end')->nullable();
            $table->json('cursor_before_json')->nullable();
            $table->json('cursor_after_json')->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->json('metrics_json')->nullable();
            $table->json('rate_limit_json')->nullable();
            $table->json('retry_json')->nullable();
            $table->timestamp('next_retry_at')->nullable()->index();
            $table->timestamp('cancelled_at')->nullable()->index();
            $table->string('idempotency_key', 191)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('connector_account_id')->references('id')->on('connector_accounts')->cascadeOnDelete();
            $table->foreign('connector_dataset_id')->references('id')->on('connector_datasets')->nullOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->unique('idempotency_key', 'connector_sync_runs_idempotency_key_unique');
            $table->index(['workspace_id', 'provider_key', 'status'], 'connector_sync_runs_workspace_provider_status_idx');
        });

        Schema::create('connector_health_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('connector_account_id')->index();
            $table->uuid('connector_dataset_id')->nullable()->index();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->string('provider_key', 120)->index();
            $table->string('severity', 40)->default('info')->index();
            $table->string('event_type', 120)->index();
            $table->text('message');
            $table->json('context_json')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->foreign('connector_account_id')->references('id')->on('connector_accounts')->cascadeOnDelete();
            $table->foreign('connector_dataset_id')->references('id')->on('connector_datasets')->nullOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->index(['workspace_id', 'severity', 'occurred_at'], 'connector_health_events_workspace_severity_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_health_events');
        Schema::dropIfExists('connector_sync_runs');
        Schema::dropIfExists('connector_datasets');
        Schema::dropIfExists('connector_scopes');
        Schema::dropIfExists('connector_oauth_states');
        Schema::dropIfExists('connector_tokens');
        Schema::dropIfExists('connector_credentials');
        Schema::dropIfExists('connector_accounts');
        Schema::dropIfExists('connector_providers');
    }
};
