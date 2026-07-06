<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitored_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->string('source_type', 80)->index();
            $table->string('name', 220);
            $table->string('base_url', 2048)->nullable();
            $table->string('domain', 255)->nullable()->index();
            $table->string('status', 40)->default('new')->index();
            $table->unsignedTinyInteger('trust_level')->default(0);
            $table->decimal('authority_score', 8, 2)->default(0);
            $table->string('polling_frequency', 60)->nullable();
            $table->json('crawl_policy_json')->nullable();
            $table->json('fetch_config_json')->nullable();
            $table->json('discovery_config_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('last_discovered_at')->nullable();
            $table->timestamp('last_fetched_at')->nullable();
            $table->timestamp('last_processed_at')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->index(['workspace_id', 'source_type', 'status'], 'monitored_sources_workspace_type_status_idx');
        });

        Schema::create('monitored_pages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('monitored_source_id')->nullable()->index();
            $table->string('canonical_url', 2048);
            $table->char('canonical_url_hash', 64);
            $table->string('first_seen_url', 2048);
            $table->char('first_seen_url_hash', 64)->nullable();
            $table->string('final_url', 2048)->nullable();
            $table->char('final_url_hash', 64)->nullable();
            $table->string('domain', 255)->index();
            $table->string('path', 2048)->nullable();
            $table->string('source_type', 80)->index();
            $table->string('page_type', 80)->nullable()->index();
            $table->string('content_type', 120)->nullable();
            $table->string('publisher_name', 220)->nullable();
            $table->string('language_current', 20)->nullable();
            $table->string('title_current', 500)->nullable();
            $table->timestamp('published_at_current')->nullable();
            $table->timestamp('first_seen_at')->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('last_fetched_at')->nullable()->index();
            $table->timestamp('last_changed_at')->nullable()->index();
            $table->string('crawl_status', 40)->default('new')->index();
            $table->string('indexability_status', 40)->nullable()->index();
            $table->string('dedupe_key', 191)->nullable()->index();
            $table->string('syndication_group_key', 191)->nullable()->index();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('monitored_source_id')->references('id')->on('monitored_sources')->nullOnDelete();
            $table->unique(['workspace_id', 'canonical_url_hash'], 'monitored_pages_workspace_canonical_hash_unique');
            $table->index(['workspace_id', 'source_type', 'crawl_status'], 'monitored_pages_workspace_source_status_idx');
            $table->index(['workspace_id', 'domain', 'last_seen_at'], 'monitored_pages_workspace_domain_seen_idx');
            $table->unique(['workspace_id', 'first_seen_url_hash'], 'monitored_pages_workspace_first_seen_hash_unique');
        });

        Schema::create('page_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('monitored_page_id')->index();
            $table->unsignedInteger('snapshot_number');
            $table->string('requested_url', 2048);
            $table->string('final_url', 2048)->nullable();
            $table->string('canonical_url', 2048)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable()->index();
            $table->string('content_type', 120)->nullable();
            $table->json('response_headers_json')->nullable();
            $table->json('redirect_chain_json')->nullable();
            $table->string('raw_html_path', 2048)->nullable();
            $table->longText('raw_html')->nullable();
            $table->unsignedInteger('raw_html_bytes')->nullable();
            $table->text('raw_html_preview')->nullable();
            $table->char('raw_html_hash', 64)->nullable()->index();
            $table->char('text_hash', 64)->nullable()->index();
            $table->boolean('content_changed')->default(false)->index();
            $table->boolean('canonical_conflict')->default(false)->index();
            $table->unsignedInteger('fetch_duration_ms')->nullable();
            $table->timestamp('fetched_at')->nullable()->index();
            $table->string('fetcher_version', 80)->nullable();
            $table->string('error_code', 80)->nullable()->index();
            $table->text('error_message')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('monitored_page_id')->references('id')->on('monitored_pages')->cascadeOnDelete();
            $table->unique(['monitored_page_id', 'snapshot_number'], 'page_snapshots_page_number_unique');
            $table->index(['workspace_id', 'monitored_page_id', 'fetched_at'], 'page_snapshots_workspace_page_fetched_idx');
        });

        Schema::create('page_content_extractions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('monitored_page_id')->index();
            $table->uuid('page_snapshot_id')->index();
            $table->string('extraction_method', 80)->nullable();
            $table->string('extractor_version', 80)->nullable();
            $table->string('title', 500)->nullable();
            $table->text('meta_description')->nullable();
            $table->string('h1', 500)->nullable();
            $table->json('headings_json')->nullable();
            $table->string('author', 220)->nullable();
            $table->string('publisher', 220)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('language', 20)->nullable()->index();
            $table->text('summary')->nullable();
            $table->longText('main_text')->nullable();
            $table->string('main_text_path', 2048)->nullable();
            $table->char('main_text_hash', 64)->nullable()->index();
            $table->unsignedInteger('main_text_bytes')->nullable();
            $table->text('main_text_preview')->nullable();
            $table->longText('main_html')->nullable();
            $table->string('main_html_path', 2048)->nullable();
            $table->char('main_html_hash', 64)->nullable();
            $table->unsignedInteger('main_html_bytes')->nullable();
            $table->unsignedInteger('word_count')->nullable()->index();
            $table->unsignedInteger('char_count')->nullable();
            $table->unsignedInteger('estimated_tokens')->nullable();
            $table->decimal('content_depth_score', 8, 2)->nullable();
            $table->decimal('quality_score', 8, 2)->nullable();
            $table->json('structured_data_json')->nullable();
            $table->json('images_json')->nullable();
            $table->json('media_json')->nullable();
            $table->json('outbound_links_json')->nullable();
            $table->json('internal_links_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('monitored_page_id')->references('id')->on('monitored_pages')->cascadeOnDelete();
            $table->foreign('page_snapshot_id')->references('id')->on('page_snapshots')->cascadeOnDelete();
            $table->unique('page_snapshot_id', 'page_content_extractions_snapshot_unique');
            $table->index(['workspace_id', 'monitored_page_id', 'language'], 'page_content_extractions_workspace_page_lang_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_content_extractions');
        Schema::dropIfExists('page_snapshots');
        Schema::dropIfExists('monitored_pages');
        Schema::dropIfExists('monitored_sources');
    }
};
