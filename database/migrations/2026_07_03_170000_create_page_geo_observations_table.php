<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_geo_observations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('monitored_page_id')->nullable()->index();
            $table->uuid('page_snapshot_id')->nullable()->index();
            $table->foreignId('llm_tracking_query_id')->nullable()->constrained('llm_tracking_queries')->nullOnDelete();
            $table->foreignId('llm_tracking_query_run_id')->nullable()->constrained('llm_tracking_query_runs')->nullOnDelete();
            $table->text('query');
            $table->char('query_hash', 64)->index();
            $table->string('answer_engine', 80)->default('llm')->index();
            $table->string('provider', 80)->nullable()->index();
            $table->string('model', 160)->nullable()->index();
            $table->string('locale', 40)->nullable()->index();
            $table->timestamp('observed_at')->index();
            $table->string('cited_url', 2048)->nullable();
            $table->char('cited_url_hash', 64)->nullable();
            $table->string('cited_domain', 255)->nullable()->index();
            $table->unsignedSmallInteger('citation_position')->nullable();
            $table->unsignedSmallInteger('citation_count')->default(0);
            $table->json('mentioned_brands_json')->nullable();
            $table->json('mentioned_competitors_json')->nullable();
            $table->boolean('client_cited')->default(false)->index();
            $table->boolean('competitors_cited')->default(false)->index();
            $table->boolean('brand_mentioned')->default(false)->index();
            $table->string('sentiment', 40)->nullable()->index();
            $table->decimal('topic_ownership_score', 8, 4)->nullable();
            $table->decimal('consistency_score', 8, 4)->nullable();
            $table->decimal('geo_visibility_score', 8, 4)->default(0);
            $table->json('breakdown_json')->nullable();
            $table->text('answer_summary')->nullable();
            $table->json('raw_payload_json')->nullable();
            $table->string('retention_policy', 80)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('monitored_page_id')->references('id')->on('monitored_pages')->nullOnDelete();
            $table->foreign('page_snapshot_id')->references('id')->on('page_snapshots')->nullOnDelete();
            $table->index(['workspace_id', 'query_hash', 'answer_engine', 'provider', 'model', 'locale', 'observed_at'], 'page_geo_observations_query_history_idx');
            $table->index(['workspace_id', 'monitored_page_id', 'observed_at'], 'page_geo_observations_page_history_idx');
            $table->unique(['llm_tracking_query_run_id', 'cited_url_hash'], 'page_geo_observations_run_url_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_geo_observations');
    }
};
