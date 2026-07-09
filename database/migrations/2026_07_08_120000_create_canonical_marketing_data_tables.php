<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_metric_definitions')) {
            Schema::create('marketing_metric_definitions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('metric_key', 160)->unique();
                $table->string('display_name', 220);
                $table->text('description')->nullable();
                $table->string('value_type', 40)->default('decimal')->index();
                $table->string('default_unit', 60)->nullable()->index();
                $table->string('aggregation', 40)->default('sum')->index();
                $table->string('direction', 40)->nullable()->index();
                $table->boolean('is_active')->default(true)->index();
                $table->json('metadata_json')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('marketing_dimension_definitions')) {
            Schema::create('marketing_dimension_definitions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('dimension_key', 160)->unique();
                $table->string('display_name', 220);
                $table->text('description')->nullable();
                $table->string('value_type', 40)->default('string')->index();
                $table->boolean('is_active')->default(true)->index();
                $table->json('metadata_json')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('marketing_observations')) {
            Schema::create('marketing_observations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->index();
                $table->uuid('client_site_id')->nullable()->index();
                $table->uuid('connector_provider_id')->index();
                $table->uuid('connector_account_id')->index();
                $table->uuid('connector_dataset_id')->index();
                $table->uuid('connector_sync_run_id')->nullable()->index();
                $table->uuid('marketing_metric_definition_id')->nullable()->index();
                $table->string('metric_key', 160)->index();
                $table->decimal('metric_value', 30, 10);
                $table->string('unit', 60)->nullable()->index();
                $table->timestamp('period_start')->index();
                $table->timestamp('period_end')->index();
                $table->string('granularity', 40)->index();
                $table->timestamp('observed_at')->nullable()->index();
                $table->decimal('confidence_score', 8, 4)->nullable();
                $table->decimal('quality_score', 8, 4)->nullable();
                $table->string('external_id', 191)->nullable()->index();
                $table->char('fingerprint', 64)->unique();
                $table->json('source_metadata_json')->nullable();
                $table->json('quality_metadata_json')->nullable();
                $table->json('raw_metadata_json')->nullable();
                $table->string('raw_payload_ref', 500)->nullable();
                $table->timestamps();

                $table->foreign('workspace_id', 'mk_obs_workspace_fk')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign('client_site_id', 'mk_obs_site_fk')->references('id')->on('client_sites')->nullOnDelete();
                $table->foreign('connector_provider_id', 'mk_obs_provider_fk')->references('id')->on('connector_providers')->cascadeOnDelete();
                $table->foreign('connector_account_id', 'mk_obs_account_fk')->references('id')->on('connector_accounts')->cascadeOnDelete();
                $table->foreign('connector_dataset_id', 'mk_obs_dataset_fk')->references('id')->on('connector_datasets')->cascadeOnDelete();
                $table->foreign('connector_sync_run_id', 'mk_obs_sync_run_fk')->references('id')->on('connector_sync_runs')->nullOnDelete();
                $table->foreign('marketing_metric_definition_id', 'mk_obs_metric_def_fk')
                    ->references('id')
                    ->on('marketing_metric_definitions')
                    ->nullOnDelete();
                $table->index(['workspace_id', 'metric_key', 'granularity', 'period_start'], 'marketing_observations_workspace_metric_period_idx');
                $table->index(['workspace_id', 'connector_dataset_id', 'period_start'], 'marketing_observations_workspace_dataset_period_idx');
                $table->index(['client_site_id', 'metric_key', 'period_start'], 'marketing_observations_site_metric_period_idx');
            });
        }

        if (! Schema::hasTable('marketing_observation_dimensions')) {
            Schema::create('marketing_observation_dimensions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('marketing_observation_id')->index();
                $table->uuid('marketing_dimension_definition_id')->nullable()->index();
                $table->string('dimension_key', 160)->index();
                $table->text('dimension_value')->nullable();
                $table->string('dimension_value_normalized', 500)->nullable();
                $table->char('dimension_value_hash', 64)->index();
                $table->json('metadata_json')->nullable();
                $table->timestamps();

                $table->foreign('marketing_observation_id', 'mk_obs_dim_obs_fk')
                    ->references('id')
                    ->on('marketing_observations')
                    ->cascadeOnDelete();
                $table->foreign('marketing_dimension_definition_id', 'mk_obs_dim_def_fk')
                    ->references('id')
                    ->on('marketing_dimension_definitions')
                    ->nullOnDelete();
                $table->unique(
                    ['marketing_observation_id', 'dimension_key', 'dimension_value_hash'],
                    'marketing_observation_dimensions_unique'
                );
                $table->index(['dimension_key', 'dimension_value_hash'], 'marketing_observation_dimensions_lookup_idx');
            });
        } else {
            $this->addObservationDimensionIndexes();
            $this->addObservationDimensionForeignKeys();
        }

        if (! Schema::hasTable('marketing_attributions')) {
            Schema::create('marketing_attributions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->index();
                $table->uuid('client_site_id')->nullable()->index();
                $table->uuid('marketing_observation_id')->index();
                $table->string('attribution_type', 80)->index();
                $table->string('attributed_type', 160)->nullable()->index();
                $table->string('attributed_id', 191)->nullable()->index();
                $table->string('attribution_key', 160)->nullable()->index();
                $table->text('attribution_value')->nullable();
                $table->decimal('weight', 12, 6)->default(1);
                $table->decimal('confidence_score', 8, 4)->nullable();
                $table->string('model_key', 160)->nullable()->index();
                $table->json('source_metadata_json')->nullable();
                $table->json('metadata_json')->nullable();
                $table->timestamps();

                $table->foreign('workspace_id', 'mk_attr_workspace_fk')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign('client_site_id', 'mk_attr_site_fk')->references('id')->on('client_sites')->nullOnDelete();
                $table->foreign('marketing_observation_id', 'mk_attr_obs_fk')
                    ->references('id')
                    ->on('marketing_observations')
                    ->cascadeOnDelete();
                $table->index(['workspace_id', 'attribution_type', 'model_key'], 'marketing_attributions_workspace_type_model_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_attributions');
        Schema::dropIfExists('marketing_observation_dimensions');
        Schema::dropIfExists('marketing_observations');
        Schema::dropIfExists('marketing_dimension_definitions');
        Schema::dropIfExists('marketing_metric_definitions');
    }

    private function addObservationDimensionForeignKeys(): void
    {
        $this->addForeignIfMissing(
            'marketing_observation_dimensions',
            'mk_obs_dim_obs_fk',
            function (Blueprint $table): void {
                $table->foreign('marketing_observation_id', 'mk_obs_dim_obs_fk')
                    ->references('id')
                    ->on('marketing_observations')
                    ->cascadeOnDelete();
            }
        );

        $this->addForeignIfMissing(
            'marketing_observation_dimensions',
            'mk_obs_dim_def_fk',
            function (Blueprint $table): void {
                $table->foreign('marketing_dimension_definition_id', 'mk_obs_dim_def_fk')
                    ->references('id')
                    ->on('marketing_dimension_definitions')
                    ->nullOnDelete();
            }
        );
    }

    private function addObservationDimensionIndexes(): void
    {
        $this->addIndexIfMissing(
            'marketing_observation_dimensions',
            'marketing_observation_dimensions_unique',
            function (Blueprint $table): void {
                $table->unique(
                    ['marketing_observation_id', 'dimension_key', 'dimension_value_hash'],
                    'marketing_observation_dimensions_unique'
                );
            }
        );

        $this->addIndexIfMissing(
            'marketing_observation_dimensions',
            'marketing_observation_dimensions_lookup_idx',
            function (Blueprint $table): void {
                $table->index(['dimension_key', 'dimension_value_hash'], 'marketing_observation_dimensions_lookup_idx');
            }
        );
    }

    private function addIndexIfMissing(string $table, string $indexName, callable $callback): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if ($this->indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, $callback);
    }

    private function addForeignIfMissing(string $table, string $constraintName, callable $callback): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if ($this->foreignKeyExists($table, $constraintName)) {
            return;
        }

        Schema::table($table, $callback);
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $row = DB::selectOne(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [DB::getDatabaseName(), $table, $indexName]
        );

        return $row !== null;
    }

    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        $row = DB::selectOne(
            'SELECT 1 FROM information_schema.table_constraints WHERE table_schema = ? AND table_name = ? AND constraint_name = ? AND constraint_type = ? LIMIT 1',
            [DB::getDatabaseName(), $table, $constraintName, 'FOREIGN KEY']
        );

        return $row !== null;
    }
};
