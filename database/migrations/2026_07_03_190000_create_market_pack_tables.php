<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_packs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key', 160)->unique();
            $table->string('name', 220);
            $table->text('description')->nullable();
            $table->string('market_category', 120)->nullable()->index();
            $table->string('status', 40)->default('active')->index();
            $table->string('version', 40)->nullable();
            $table->string('locale', 20)->default('en');
            $table->json('defaults_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('market_pack_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('market_pack_id')->index();
            $table->string('key', 160);
            $table->string('name', 220);
            $table->string('source_type', 80)->index();
            $table->string('base_url', 2048)->nullable();
            $table->string('domain', 255)->nullable()->index();
            $table->string('status', 40)->default('active')->index();
            $table->unsignedTinyInteger('trust_level')->default(0);
            $table->decimal('authority_score', 8, 2)->default(0);
            $table->string('polling_frequency', 60)->nullable();
            $table->json('crawl_policy_json')->nullable();
            $table->json('fetch_config_json')->nullable();
            $table->json('discovery_config_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('market_pack_id')->references('id')->on('market_packs')->cascadeOnDelete();
            $table->unique(['market_pack_id', 'key'], 'market_pack_sources_pack_key_unique');
        });

        Schema::create('market_pack_competitors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('market_pack_id')->index();
            $table->string('key', 160);
            $table->string('name', 220);
            $table->string('domain', 255)->nullable()->index();
            $table->json('aliases_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('market_pack_id')->references('id')->on('market_packs')->cascadeOnDelete();
            $table->unique(['market_pack_id', 'key'], 'market_pack_competitors_pack_key_unique');
        });

        Schema::create('market_pack_themes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('market_pack_id')->index();
            $table->string('key', 160);
            $table->string('name', 220);
            $table->text('description')->nullable();
            $table->decimal('weight', 8, 4)->default(1);
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('market_pack_id')->references('id')->on('market_packs')->cascadeOnDelete();
            $table->unique(['market_pack_id', 'key'], 'market_pack_themes_pack_key_unique');
        });

        Schema::create('market_pack_keywords', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('market_pack_id')->index();
            $table->uuid('market_pack_theme_id')->nullable()->index();
            $table->string('keyword', 220);
            $table->string('keyword_type', 80)->default('theme')->index();
            $table->string('intent', 80)->nullable()->index();
            $table->decimal('weight', 8, 4)->default(1);
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('market_pack_id')->references('id')->on('market_packs')->cascadeOnDelete();
            $table->foreign('market_pack_theme_id')->references('id')->on('market_pack_themes')->nullOnDelete();
            $table->index(['market_pack_id', 'keyword'], 'market_pack_keywords_pack_keyword_idx');
        });

        Schema::create('market_pack_metrics', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('market_pack_id')->index();
            $table->string('key', 160);
            $table->string('name', 220);
            $table->string('metric_type', 80)->default('score')->index();
            $table->decimal('default_value', 12, 4)->nullable();
            $table->string('unit', 40)->nullable();
            $table->string('direction', 40)->nullable();
            $table->decimal('weight', 8, 4)->default(1);
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('market_pack_id')->references('id')->on('market_packs')->cascadeOnDelete();
            $table->unique(['market_pack_id', 'key'], 'market_pack_metrics_pack_key_unique');
        });

        Schema::create('market_pack_alert_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('market_pack_id')->index();
            $table->string('key', 160);
            $table->string('name', 220);
            $table->string('trigger', 120)->index();
            $table->json('conditions_json')->nullable();
            $table->unsignedInteger('cooldown_minutes')->default(60);
            $table->string('severity', 40)->default('medium')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('market_pack_id')->references('id')->on('market_packs')->cascadeOnDelete();
            $table->unique(['market_pack_id', 'key'], 'market_pack_alert_templates_pack_key_unique');
        });

        Schema::create('market_pack_scoring_models', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('market_pack_id')->index();
            $table->string('key', 160);
            $table->string('name', 220);
            $table->string('model_type', 80)->default('page_score')->index();
            $table->string('model_version', 40)->nullable();
            $table->json('weights_json')->nullable();
            $table->json('defaults_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('market_pack_id')->references('id')->on('market_packs')->cascadeOnDelete();
            $table->unique(['market_pack_id', 'key'], 'market_pack_scoring_models_pack_key_unique');
        });

        Schema::create('market_pack_installations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('market_pack_id')->index();
            $table->string('status', 40)->default('active')->index();
            $table->timestamp('installed_at')->nullable()->index();
            $table->json('customized_config_json')->nullable();
            $table->json('source_overrides_json')->nullable();
            $table->json('competitor_overrides_json')->nullable();
            $table->json('theme_overrides_json')->nullable();
            $table->json('keyword_overrides_json')->nullable();
            $table->json('alert_overrides_json')->nullable();
            $table->json('scoring_overrides_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('market_pack_id')->references('id')->on('market_packs')->cascadeOnDelete();
            $table->unique(['workspace_id', 'market_pack_id'], 'market_pack_installations_workspace_pack_unique');
            $table->index(['workspace_id', 'status'], 'market_pack_installations_workspace_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_pack_installations');
        Schema::dropIfExists('market_pack_scoring_models');
        Schema::dropIfExists('market_pack_alert_templates');
        Schema::dropIfExists('market_pack_metrics');
        Schema::dropIfExists('market_pack_keywords');
        Schema::dropIfExists('market_pack_themes');
        Schema::dropIfExists('market_pack_competitors');
        Schema::dropIfExists('market_pack_sources');
        Schema::dropIfExists('market_packs');
    }
};
