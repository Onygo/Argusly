<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_quota_budgets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('connector_account_id')->nullable()->index();
            $table->string('provider_key', 120)->index();
            $table->string('budget_type', 40)->index();
            $table->unsignedInteger('limit_value')->default(0);
            $table->unsignedInteger('used_value')->default(0);
            $table->unsignedTinyInteger('warning_threshold_percent')->default(80);
            $table->string('status', 40)->default('ok')->index();
            $table->timestamp('period_started_at')->nullable()->index();
            $table->timestamp('period_ends_at')->nullable()->index();
            $table->timestamp('reset_at')->nullable()->index();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('connector_account_id')->references('id')->on('connector_accounts')->cascadeOnDelete();
            $table->index(['workspace_id', 'provider_key', 'budget_type', 'status'], 'connector_quota_workspace_provider_type_status_idx');
        });

        Schema::create('connector_backfill_ranges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('connector_account_id')->index();
            $table->uuid('connector_dataset_id')->index();
            $table->foreignId('requested_by_user_id')->nullable()->index();
            $table->string('provider_key', 120)->index();
            $table->string('dataset_key', 160)->index();
            $table->string('status', 40)->default('pending')->index();
            $table->date('range_start')->index();
            $table->date('range_end')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->uuid('connector_sync_run_id')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('connector_account_id')->references('id')->on('connector_accounts')->cascadeOnDelete();
            $table->foreign('connector_dataset_id')->references('id')->on('connector_datasets')->cascadeOnDelete();
            $table->foreign('requested_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('connector_sync_run_id')->references('id')->on('connector_sync_runs')->nullOnDelete();
            $table->index(['workspace_id', 'provider_key', 'status'], 'connector_backfills_workspace_provider_status_idx');
        });

        Schema::create('connector_async_report_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('connector_account_id')->index();
            $table->uuid('connector_dataset_id')->nullable()->index();
            $table->string('provider_key', 120)->index();
            $table->string('dataset_key', 160)->nullable()->index();
            $table->string('report_type', 120)->index();
            $table->string('external_report_id', 191)->nullable()->index();
            $table->string('status', 40)->default('pending')->index();
            $table->timestamp('requested_at')->nullable()->index();
            $table->timestamp('ready_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable()->index();
            $table->json('rate_limit_json')->nullable();
            $table->json('payload_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('connector_account_id')->references('id')->on('connector_accounts')->cascadeOnDelete();
            $table->foreign('connector_dataset_id')->references('id')->on('connector_datasets')->nullOnDelete();
            $table->index(['workspace_id', 'provider_key', 'status'], 'connector_report_jobs_workspace_provider_status_idx');
        });

        Schema::create('connector_field_mapping_preparations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('connector_account_id')->index();
            $table->uuid('connector_dataset_id')->nullable()->index();
            $table->string('provider_key', 120)->index();
            $table->string('object_key', 120)->index();
            $table->string('status', 40)->default('prepared')->index();
            $table->json('source_fields_json')->nullable();
            $table->json('target_preview_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('prepared_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('connector_account_id')->references('id')->on('connector_accounts')->cascadeOnDelete();
            $table->foreign('connector_dataset_id')->references('id')->on('connector_datasets')->nullOnDelete();
            $table->unique(['connector_account_id', 'object_key'], 'connector_field_mapping_account_object_unique');
        });

        Schema::create('connector_webhook_registrations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('connector_account_id')->index();
            $table->string('provider_key', 120)->index();
            $table->string('status', 40)->default('prepared')->index();
            $table->json('event_types_json')->nullable();
            $table->string('target_url', 2048)->nullable();
            $table->string('external_webhook_id', 191)->nullable()->index();
            $table->timestamp('registered_at')->nullable()->index();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('connector_account_id')->references('id')->on('connector_accounts')->cascadeOnDelete();
            $table->unique(['connector_account_id', 'provider_key'], 'connector_webhook_account_provider_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_webhook_registrations');
        Schema::dropIfExists('connector_field_mapping_preparations');
        Schema::dropIfExists('connector_async_report_jobs');
        Schema::dropIfExists('connector_backfill_ranges');
        Schema::dropIfExists('connector_quota_budgets');
    }
};
