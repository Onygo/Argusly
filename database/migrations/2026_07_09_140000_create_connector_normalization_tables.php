<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('connector_normalization_runs')) {
            Schema::create('connector_normalization_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('connector_account_id')->nullable()->index();
            $table->uuid('connector_dataset_id')->nullable()->index();
            $table->uuid('connector_sync_run_id')->nullable()->index();
            $table->uuid('connector_backfill_range_id')->nullable()->index();
            $table->string('provider', 120)->index();
            $table->string('dataset_key', 160)->nullable()->index();
            $table->string('trigger', 80)->default('sync')->index();
            $table->string('status', 40)->default('pending')->index();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('records_processed')->default(0);
            $table->unsignedInteger('records_written')->default(0);
            $table->unsignedInteger('records_failed')->default(0);
            $table->unsignedInteger('records_skipped')->default(0);
            $table->text('latest_error')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('connector_account_id', 'cn_runs_account_fk')->references('id')->on('connector_accounts')->nullOnDelete();
            $table->foreign('connector_dataset_id', 'cn_runs_dataset_fk')->references('id')->on('connector_datasets')->nullOnDelete();
            $table->foreign('connector_sync_run_id', 'cn_runs_sync_run_fk')->references('id')->on('connector_sync_runs')->nullOnDelete();
            $table->foreign('connector_backfill_range_id', 'cn_runs_backfill_fk')->references('id')->on('connector_backfill_ranges')->nullOnDelete();
            $table->index(['workspace_id', 'provider', 'status'], 'cn_runs_workspace_provider_status_idx');
            $table->index(['connector_account_id', 'created_at'], 'cn_runs_account_created_idx');
            });
        }

        if (! Schema::hasTable('connector_normalization_run_items')) {
            Schema::create('connector_normalization_run_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('connector_normalization_run_id')->index('cn_items_run_id_idx');
            $table->uuid('connector_raw_record_id')->nullable()->index('cn_items_raw_record_idx');
            $table->string('entity_type', 80)->nullable()->index();
            $table->string('status', 40)->default('pending')->index();
            $table->unsignedInteger('records_written')->default(0);
            $table->text('error_message')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('connector_normalization_run_id', 'cn_items_run_fk')
                ->references('id')
                ->on('connector_normalization_runs')
                ->cascadeOnDelete();
            $table->foreign('connector_raw_record_id', 'cn_items_raw_record_fk')
                ->references('id')
                ->on('connector_raw_records')
                ->nullOnDelete();
            $table->unique(['connector_normalization_run_id', 'connector_raw_record_id'], 'cn_items_run_raw_unique');
            $table->index(['connector_normalization_run_id', 'status'], 'cn_items_run_status_idx');
            });
        } else {
            $this->ensureNormalizationRunItemIndexesAndKeys();
        }

        if (! Schema::hasTable('connector_normalized_marketing_accounts')) {
            Schema::create('connector_normalized_marketing_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('connector_account_id')->nullable()->index('cn_m_accounts_account_idx');
            $table->string('provider', 120)->index();
            $table->string('provider_account_id', 191);
            $table->string('name', 220)->nullable();
            $table->string('status', 80)->nullable()->index();
            $table->string('currency', 16)->nullable()->index();
            $table->string('timezone', 80)->nullable();
            $table->json('raw_reference')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('connector_account_id', 'cn_m_accounts_account_fk')->references('id')->on('connector_accounts')->nullOnDelete();
            $table->unique(['workspace_id', 'provider', 'provider_account_id'], 'cn_m_accounts_workspace_provider_unique');
            });
        }

        if (! Schema::hasTable('connector_normalized_campaigns')) {
            Schema::create('connector_normalized_campaigns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('connector_account_id')->nullable()->index();
            $table->string('provider', 120)->index();
            $table->string('provider_campaign_id', 191);
            $table->uuid('account_id')->nullable()->index();
            $table->string('name', 220)->nullable();
            $table->string('objective', 160)->nullable()->index();
            $table->string('status', 80)->nullable()->index();
            $table->date('start_date')->nullable()->index();
            $table->date('end_date')->nullable()->index();
            $table->decimal('budget', 20, 6)->nullable();
            $table->string('currency', 16)->nullable()->index();
            $table->json('raw_reference')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('connector_account_id', 'cn_campaigns_account_fk')->references('id')->on('connector_accounts')->nullOnDelete();
            $table->foreign('account_id', 'cn_campaigns_m_account_fk')
                ->references('id')
                ->on('connector_normalized_marketing_accounts')
                ->nullOnDelete();
            $table->unique(['workspace_id', 'provider', 'provider_campaign_id'], 'cn_campaigns_workspace_provider_unique');
            });
        }

        if (! Schema::hasTable('connector_normalized_ad_groups')) {
            Schema::create('connector_normalized_ad_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('connector_account_id')->nullable()->index();
            $table->string('provider', 120)->index();
            $table->string('provider_ad_group_id', 191);
            $table->uuid('campaign_id')->nullable()->index();
            $table->string('name', 220)->nullable();
            $table->string('status', 80)->nullable()->index();
            $table->string('bid_strategy', 160)->nullable();
            $table->decimal('budget', 20, 6)->nullable();
            $table->json('raw_reference')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('connector_account_id', 'cn_ad_groups_account_fk')->references('id')->on('connector_accounts')->nullOnDelete();
            $table->foreign('campaign_id', 'cn_ad_groups_campaign_fk')->references('id')->on('connector_normalized_campaigns')->nullOnDelete();
            $table->unique(['workspace_id', 'provider', 'provider_ad_group_id'], 'cn_ad_groups_workspace_provider_unique');
            });
        }

        if (! Schema::hasTable('connector_normalized_ads')) {
            Schema::create('connector_normalized_ads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('connector_account_id')->nullable()->index();
            $table->string('provider', 120)->index();
            $table->string('provider_ad_id', 191);
            $table->uuid('campaign_id')->nullable()->index();
            $table->uuid('ad_group_id')->nullable()->index();
            $table->string('name', 220)->nullable();
            $table->string('status', 80)->nullable()->index();
            $table->string('creative_type', 160)->nullable();
            $table->text('landing_url')->nullable();
            $table->json('raw_reference')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('connector_account_id', 'cn_ads_account_fk')->references('id')->on('connector_accounts')->nullOnDelete();
            $table->foreign('campaign_id', 'cn_ads_campaign_fk')->references('id')->on('connector_normalized_campaigns')->nullOnDelete();
            $table->foreign('ad_group_id', 'cn_ads_ad_group_fk')->references('id')->on('connector_normalized_ad_groups')->nullOnDelete();
            $table->unique(['workspace_id', 'provider', 'provider_ad_id'], 'cn_ads_workspace_provider_unique');
            });
        }

        if (! Schema::hasTable('connector_normalized_daily_performances')) {
            Schema::create('connector_normalized_daily_performances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('connector_account_id')->nullable()->index('cn_perf_account_idx');
            $table->string('provider', 120)->index();
            $table->string('entity_type', 80)->index();
            $table->string('entity_id', 191)->index();
            $table->date('date')->index();
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->decimal('cost', 20, 6)->default(0);
            $table->decimal('conversions', 20, 6)->default(0);
            $table->decimal('ctr', 12, 6)->nullable();
            $table->decimal('cpc', 20, 6)->nullable();
            $table->decimal('cpm', 20, 6)->nullable();
            $table->decimal('revenue', 20, 6)->nullable();
            $table->json('raw_reference')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('connector_account_id', 'cn_perf_account_fk')->references('id')->on('connector_accounts')->nullOnDelete();
            $table->unique(['workspace_id', 'provider', 'entity_type', 'entity_id', 'date'], 'cn_perf_workspace_provider_entity_date_unique');
            $table->index(['workspace_id', 'provider', 'date'], 'cn_perf_workspace_provider_date_idx');
            });
        }

        if (! Schema::hasTable('connector_normalized_crm_companies')) {
            Schema::create('connector_normalized_crm_companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('connector_account_id')->nullable()->index();
            $table->string('provider', 120)->index();
            $table->string('provider_company_id', 191);
            $table->string('name', 220)->nullable();
            $table->string('domain', 255)->nullable()->index();
            $table->string('industry', 160)->nullable()->index();
            $table->string('size', 80)->nullable();
            $table->string('owner_id', 191)->nullable()->index();
            $table->string('lifecycle_stage', 160)->nullable()->index();
            $table->json('raw_reference')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('connector_account_id', 'cn_companies_account_fk')->references('id')->on('connector_accounts')->nullOnDelete();
            $table->unique(['workspace_id', 'provider', 'provider_company_id'], 'cn_companies_workspace_provider_unique');
            });
        }

        if (! Schema::hasTable('connector_normalized_crm_contacts')) {
            Schema::create('connector_normalized_crm_contacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('connector_account_id')->nullable()->index();
            $table->string('provider', 120)->index();
            $table->string('provider_contact_id', 191);
            $table->uuid('company_id')->nullable()->index();
            $table->char('email_hash', 64)->nullable()->index();
            $table->string('first_name', 160)->nullable();
            $table->string('last_name', 160)->nullable();
            $table->string('job_title', 220)->nullable();
            $table->string('owner_id', 191)->nullable()->index();
            $table->string('lifecycle_stage', 160)->nullable()->index();
            $table->json('raw_reference')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('connector_account_id', 'cn_contacts_account_fk')->references('id')->on('connector_accounts')->nullOnDelete();
            $table->foreign('company_id', 'cn_contacts_company_fk')->references('id')->on('connector_normalized_crm_companies')->nullOnDelete();
            $table->unique(['workspace_id', 'provider', 'provider_contact_id'], 'cn_contacts_workspace_provider_unique');
            });
        }

        if (! Schema::hasTable('connector_normalized_crm_deals')) {
            Schema::create('connector_normalized_crm_deals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('connector_account_id')->nullable()->index();
            $table->string('provider', 120)->index();
            $table->string('provider_deal_id', 191);
            $table->uuid('company_id')->nullable()->index();
            $table->uuid('contact_id')->nullable()->index();
            $table->string('pipeline', 160)->nullable()->index();
            $table->string('stage', 160)->nullable()->index();
            $table->decimal('amount', 20, 6)->nullable();
            $table->string('currency', 16)->nullable()->index();
            $table->decimal('probability', 8, 4)->nullable();
            $table->date('close_date')->nullable()->index();
            $table->string('owner_id', 191)->nullable()->index();
            $table->string('status', 80)->nullable()->index();
            $table->json('raw_reference')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('connector_account_id', 'cn_deals_account_fk')->references('id')->on('connector_accounts')->nullOnDelete();
            $table->foreign('company_id', 'cn_deals_company_fk')->references('id')->on('connector_normalized_crm_companies')->nullOnDelete();
            $table->foreign('contact_id', 'cn_deals_contact_fk')->references('id')->on('connector_normalized_crm_contacts')->nullOnDelete();
            $table->unique(['workspace_id', 'provider', 'provider_deal_id'], 'cn_deals_workspace_provider_unique');
            });
        }

        if (! Schema::hasTable('connector_normalized_crm_activities')) {
            Schema::create('connector_normalized_crm_activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('connector_account_id')->nullable()->index();
            $table->string('provider', 120)->index();
            $table->string('provider_activity_id', 191);
            $table->uuid('company_id')->nullable()->index();
            $table->uuid('contact_id')->nullable()->index();
            $table->uuid('deal_id')->nullable()->index();
            $table->string('type', 120)->nullable()->index();
            $table->string('subject', 500)->nullable();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->string('owner_id', 191)->nullable()->index();
            $table->json('raw_reference')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('connector_account_id', 'cn_activities_account_fk')->references('id')->on('connector_accounts')->nullOnDelete();
            $table->foreign('company_id', 'cn_activities_company_fk')->references('id')->on('connector_normalized_crm_companies')->nullOnDelete();
            $table->foreign('contact_id', 'cn_activities_contact_fk')->references('id')->on('connector_normalized_crm_contacts')->nullOnDelete();
            $table->foreign('deal_id', 'cn_activities_deal_fk')->references('id')->on('connector_normalized_crm_deals')->nullOnDelete();
            $table->unique(['workspace_id', 'provider', 'provider_activity_id'], 'cn_activities_workspace_provider_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_normalized_crm_activities');
        Schema::dropIfExists('connector_normalized_crm_deals');
        Schema::dropIfExists('connector_normalized_crm_contacts');
        Schema::dropIfExists('connector_normalized_crm_companies');
        Schema::dropIfExists('connector_normalized_daily_performances');
        Schema::dropIfExists('connector_normalized_ads');
        Schema::dropIfExists('connector_normalized_ad_groups');
        Schema::dropIfExists('connector_normalized_campaigns');
        Schema::dropIfExists('connector_normalized_marketing_accounts');
        Schema::dropIfExists('connector_normalization_run_items');
        Schema::dropIfExists('connector_normalization_runs');
    }

    private function ensureNormalizationRunItemIndexesAndKeys(): void
    {
        $this->addIndexIfMissing('connector_normalization_run_items', ['connector_normalization_run_id'], 'cn_items_run_id_idx');
        $this->addIndexIfMissing('connector_normalization_run_items', ['connector_raw_record_id'], 'cn_items_raw_record_idx');
        $this->addIndexIfMissing('connector_normalization_run_items', ['entity_type'], 'cn_items_entity_type_idx');
        $this->addIndexIfMissing('connector_normalization_run_items', ['status'], 'cn_items_status_idx');
        $this->addUniqueIfMissing(
            'connector_normalization_run_items',
            ['connector_normalization_run_id', 'connector_raw_record_id'],
            'cn_items_run_raw_unique'
        );
        $this->addIndexIfMissing(
            'connector_normalization_run_items',
            ['connector_normalization_run_id', 'status'],
            'cn_items_run_status_idx'
        );

        if (! $this->hasForeignKey('connector_normalization_run_items', 'cn_items_run_fk', ['connector_normalization_run_id'])) {
            Schema::table('connector_normalization_run_items', function (Blueprint $table): void {
                $table->foreign('connector_normalization_run_id', 'cn_items_run_fk')
                    ->references('id')
                    ->on('connector_normalization_runs')
                    ->cascadeOnDelete();
            });
        }

        if (! $this->hasForeignKey('connector_normalization_run_items', 'cn_items_raw_record_fk', ['connector_raw_record_id'])) {
            Schema::table('connector_normalization_run_items', function (Blueprint $table): void {
                $table->foreign('connector_raw_record_id', 'cn_items_raw_record_fk')
                    ->references('id')
                    ->on('connector_raw_records')
                    ->nullOnDelete();
            });
        }
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function addIndexIfMissing(string $table, array $columns, string $name): void
    {
        if (Schema::hasIndex($table, $columns)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($columns, $name): void {
            $table->index($columns, $name);
        });
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function addUniqueIfMissing(string $table, array $columns, string $name): void
    {
        if (Schema::hasIndex($table, $columns, 'unique')) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($columns, $name): void {
            $table->unique($columns, $name);
        });
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function hasForeignKey(string $table, string $name, array $columns): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (($foreignKey['name'] ?? null) === $name || ($foreignKey['columns'] ?? []) === $columns) {
                return true;
            }
        }

        return false;
    }
};
