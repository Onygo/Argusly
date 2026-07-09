<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connector_accounts', function (Blueprint $table): void {
            $table->string('sync_frequency', 80)->nullable()->after('last_synced_at');
            $table->timestamp('next_sync_at')->nullable()->index()->after('sync_frequency');
            $table->timestamp('last_api_call_at')->nullable()->index()->after('health_checked_at');
            $table->text('last_error')->nullable()->after('last_api_call_at');
            $table->json('rate_limit_json')->nullable()->after('last_error');
            $table->unsignedTinyInteger('health_score')->nullable()->after('rate_limit_json');
        });

        Schema::table('connector_sync_runs', function (Blueprint $table): void {
            $table->unsignedInteger('duration_ms')->nullable()->after('finished_at');
            $table->unsignedInteger('records_processed')->default(0)->after('duration_ms');
        });

        Schema::create('connector_raw_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('connector_provider_id')->index();
            $table->uuid('connector_account_id')->index();
            $table->uuid('connector_dataset_id')->nullable()->index();
            $table->uuid('connector_sync_run_id')->nullable()->index();
            $table->string('provider_key', 120)->index();
            $table->string('dataset_key', 160)->nullable()->index();
            $table->string('record_type', 120)->index();
            $table->string('external_record_id', 191)->nullable()->index();
            $table->string('fingerprint', 64)->unique();
            $table->timestamp('period_start')->nullable()->index();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('observed_at')->nullable()->index();
            $table->json('payload_json');
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('connector_provider_id')->references('id')->on('connector_providers')->cascadeOnDelete();
            $table->foreign('connector_account_id')->references('id')->on('connector_accounts')->cascadeOnDelete();
            $table->foreign('connector_dataset_id')->references('id')->on('connector_datasets')->nullOnDelete();
            $table->foreign('connector_sync_run_id')->references('id')->on('connector_sync_runs')->nullOnDelete();
            $table->index(['workspace_id', 'provider_key', 'record_type'], 'connector_raw_records_workspace_provider_type_idx');
            $table->index(['connector_dataset_id', 'record_type', 'period_start'], 'connector_raw_records_dataset_type_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_raw_records');

        Schema::table('connector_sync_runs', function (Blueprint $table): void {
            $table->dropColumn(['duration_ms', 'records_processed']);
        });

        Schema::table('connector_accounts', function (Blueprint $table): void {
            $table->dropColumn([
                'sync_frequency',
                'next_sync_at',
                'last_api_call_at',
                'last_error',
                'rate_limit_json',
                'health_score',
            ]);
        });
    }
};
