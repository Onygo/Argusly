<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureConnectorAccountHealthColumns();
        $this->ensureConnectorAccountProductionColumns();
        $this->ensureConnectorSyncRunProductionColumns();
        $this->ensureConnectorRawRecordsTable();
    }

    public function down(): void
    {
        if (Schema::hasTable('connector_raw_records')) {
            Schema::dropIfExists('connector_raw_records');
        }

        $this->dropColumnsIfPresent('connector_sync_runs', [
            'duration_ms',
            'records_processed',
        ]);

        $this->dropColumnsIfPresent('connector_accounts', [
            'sync_frequency',
            'next_sync_at',
            'last_api_call_at',
            'last_error',
            'rate_limit_json',
            'health_score',
        ]);
    }

    private function ensureConnectorAccountHealthColumns(): void
    {
        if (! Schema::hasColumn('connector_accounts', 'health_status')) {
            Schema::table('connector_accounts', function (Blueprint $table): void {
                $table->string('health_status', 40)->nullable()->index()->after('last_synced_at');
            });
        }

        if (! Schema::hasColumn('connector_accounts', 'health_severity')) {
            Schema::table('connector_accounts', function (Blueprint $table): void {
                $table->string('health_severity', 40)->nullable()->index()->after('health_status');
            });
        }

        if (! Schema::hasColumn('connector_accounts', 'latest_health_event_id')) {
            Schema::table('connector_accounts', function (Blueprint $table): void {
                $table->uuid('latest_health_event_id')->nullable()->index()->after('health_severity');
            });
        }

        if (! Schema::hasColumn('connector_accounts', 'health_checked_at')) {
            Schema::table('connector_accounts', function (Blueprint $table): void {
                $table->timestamp('health_checked_at')->nullable()->index()->after('latest_health_event_id');
            });
        }
    }

    private function ensureConnectorAccountProductionColumns(): void
    {
        if (! Schema::hasColumn('connector_accounts', 'sync_frequency')) {
            Schema::table('connector_accounts', function (Blueprint $table): void {
                $table->string('sync_frequency', 80)->nullable()->after('last_synced_at');
            });
        }

        if (! Schema::hasColumn('connector_accounts', 'next_sync_at')) {
            Schema::table('connector_accounts', function (Blueprint $table): void {
                $table->timestamp('next_sync_at')->nullable()->index()->after('sync_frequency');
            });
        }

        if (! Schema::hasColumn('connector_accounts', 'last_api_call_at')) {
            Schema::table('connector_accounts', function (Blueprint $table): void {
                $table->timestamp('last_api_call_at')->nullable()->index()->after('health_checked_at');
            });
        }

        if (! Schema::hasColumn('connector_accounts', 'last_error')) {
            Schema::table('connector_accounts', function (Blueprint $table): void {
                $table->text('last_error')->nullable()->after('last_api_call_at');
            });
        }

        if (! Schema::hasColumn('connector_accounts', 'rate_limit_json')) {
            Schema::table('connector_accounts', function (Blueprint $table): void {
                $table->json('rate_limit_json')->nullable()->after('last_error');
            });
        }

        if (! Schema::hasColumn('connector_accounts', 'health_score')) {
            Schema::table('connector_accounts', function (Blueprint $table): void {
                $table->unsignedTinyInteger('health_score')->nullable()->after('rate_limit_json');
            });
        }
    }

    private function ensureConnectorSyncRunProductionColumns(): void
    {
        if (! Schema::hasColumn('connector_sync_runs', 'duration_ms')) {
            Schema::table('connector_sync_runs', function (Blueprint $table): void {
                $table->unsignedInteger('duration_ms')->nullable()->after('finished_at');
            });
        }

        if (! Schema::hasColumn('connector_sync_runs', 'records_processed')) {
            Schema::table('connector_sync_runs', function (Blueprint $table): void {
                $table->unsignedInteger('records_processed')->default(0)->after('duration_ms');
            });
        }
    }

    private function ensureConnectorRawRecordsTable(): void
    {
        if (Schema::hasTable('connector_raw_records')) {
            return;
        }

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

    /**
     * @param  array<int, string>  $columns
     */
    private function dropColumnsIfPresent(string $table, array $columns): void
    {
        $existingColumns = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn($table, $column),
        ));

        if ($existingColumns === []) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($existingColumns): void {
            $table->dropColumn($existingColumns);
        });
    }
};
