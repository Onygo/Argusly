<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_serp_observations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('monitored_page_id')->nullable()->index();
            $table->uuid('page_snapshot_id')->nullable()->index();
            $table->text('query');
            $table->char('query_hash', 64)->index();
            $table->string('locale', 40)->nullable()->index();
            $table->string('country', 2)->nullable()->index();
            $table->string('device', 40)->default('desktop')->index();
            $table->string('search_engine', 80)->default('google')->index();
            $table->timestamp('observed_at')->index();
            $table->string('result_type', 80)->default('organic')->index();
            $table->unsignedSmallInteger('position')->nullable();
            $table->unsignedSmallInteger('absolute_position')->nullable();
            $table->string('page_url', 2048);
            $table->char('page_url_hash', 64)->index();
            $table->string('domain', 255)->index();
            $table->string('title', 500)->nullable();
            $table->text('snippet')->nullable();
            $table->json('serp_features_json')->nullable();
            $table->json('competitor_presence_json')->nullable();
            $table->unsignedInteger('search_volume')->nullable();
            $table->string('keyword_intent', 80)->nullable()->index();
            $table->decimal('click_potential', 8, 4)->nullable();
            $table->decimal('visibility_score', 8, 4)->default(0);
            $table->json('breakdown_json')->nullable();
            $table->json('raw_payload_json')->nullable();
            $table->string('provider_key', 80)->nullable()->index();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('monitored_page_id')->references('id')->on('monitored_pages')->nullOnDelete();
            $table->foreign('page_snapshot_id')->references('id')->on('page_snapshots')->nullOnDelete();
            $table->index(['workspace_id', 'query_hash', 'search_engine', 'country', 'device', 'observed_at'], 'page_serp_observations_query_history_idx');
            $table->index(['workspace_id', 'monitored_page_id', 'observed_at'], 'page_serp_observations_page_history_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_serp_observations');
    }
};
