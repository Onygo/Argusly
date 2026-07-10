<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribution_model_configurations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->string('key', 120);
            $table->string('label', 180);
            $table->string('model_key', 80)->index();
            $table->string('status', 40)->default('active')->index();
            $table->boolean('is_default')->default(false)->index();
            $table->unsignedSmallInteger('lookback_days')->default(90);
            $table->json('settings_json')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unique(['workspace_id', 'key'], 'attr_configs_workspace_key_unique');
        });

        Schema::create('attribution_touchpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->string('touchpoint_key', 191);
            $table->string('anonymous_or_contact_key', 191)->nullable()->index();
            $table->timestamp('occurred_at')->index();
            $table->string('channel', 120)->nullable()->index();
            $table->string('source', 120)->nullable()->index();
            $table->string('medium', 120)->nullable()->index();
            $table->string('campaign_id', 191)->nullable()->index();
            $table->string('ad_group_id', 191)->nullable()->index();
            $table->string('ad_id', 191)->nullable()->index();
            $table->text('landing_page')->nullable();
            $table->text('referrer')->nullable();
            $table->string('session_key', 191)->nullable()->index();
            $table->json('raw_reference')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unique(['workspace_id', 'touchpoint_key'], 'attr_touchpoints_workspace_key_unique');
            $table->index(['workspace_id', 'occurred_at'], 'attr_touchpoints_workspace_time_idx');
            $table->index(['workspace_id', 'source', 'medium'], 'attr_touchpoints_workspace_source_medium_idx');
        });

        Schema::create('attribution_conversions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->string('conversion_key', 191);
            $table->string('contact_key', 191)->nullable()->index();
            $table->char('email_hash', 64)->nullable()->index();
            $table->uuid('deal_id')->nullable()->index();
            $table->string('conversion_type', 120)->index();
            $table->timestamp('occurred_at')->index();
            $table->decimal('value', 20, 6)->nullable();
            $table->string('currency', 16)->nullable()->index();
            $table->string('status', 80)->nullable()->index();
            $table->json('raw_reference')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('deal_id', 'attr_conversions_deal_fk')
                ->references('id')
                ->on('connector_normalized_crm_deals')
                ->nullOnDelete();
            $table->unique(['workspace_id', 'conversion_key'], 'attr_conversions_workspace_key_unique');
            $table->index(['workspace_id', 'occurred_at'], 'attr_conversions_workspace_time_idx');
        });

        Schema::create('attribution_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('attribution_model_configuration_id')->nullable()->index();
            $table->string('model_key', 80)->index();
            $table->string('status', 40)->default('pending')->index();
            $table->timestamp('period_start')->index();
            $table->timestamp('period_end')->index();
            $table->unsignedSmallInteger('lookback_days')->default(90);
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->unsignedInteger('conversions_processed')->default(0);
            $table->unsignedInteger('touchpoints_matched')->default(0);
            $table->unsignedInteger('results_written')->default(0);
            $table->text('latest_error')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('attribution_model_configuration_id', 'attr_runs_config_fk')
                ->references('id')
                ->on('attribution_model_configurations')
                ->nullOnDelete();
            $table->index(['workspace_id', 'model_key', 'period_start', 'period_end'], 'attr_runs_workspace_model_period_idx');
        });

        Schema::create('attribution_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('attribution_run_id')->index();
            $table->uuid('attribution_touchpoint_id')->nullable()->index();
            $table->uuid('attribution_conversion_id')->index();
            $table->char('result_key', 64)->unique();
            $table->string('model_key', 80)->index();
            $table->decimal('credit', 12, 8)->default(0);
            $table->decimal('value', 20, 6)->default(0);
            $table->string('currency', 16)->nullable()->index();
            $table->string('match_confidence', 40)->default('unmatched')->index();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('attribution_run_id', 'attr_results_run_fk')
                ->references('id')
                ->on('attribution_runs')
                ->cascadeOnDelete();
            $table->foreign('attribution_touchpoint_id', 'attr_results_touchpoint_fk')
                ->references('id')
                ->on('attribution_touchpoints')
                ->nullOnDelete();
            $table->foreign('attribution_conversion_id', 'attr_results_conversion_fk')
                ->references('id')
                ->on('attribution_conversions')
                ->cascadeOnDelete();
            $table->index(['workspace_id', 'model_key', 'match_confidence'], 'attr_results_workspace_model_confidence_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribution_results');
        Schema::dropIfExists('attribution_runs');
        Schema::dropIfExists('attribution_conversions');
        Schema::dropIfExists('attribution_touchpoints');
        Schema::dropIfExists('attribution_model_configurations');
    }
};
