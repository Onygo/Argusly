<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the content_publications table for durable remote publication tracking.
 *
 * This table replaces scattered WordPress ID storage (contents.wp_post_id,
 * content_publish_targets.wp_post_id, draft.meta.client_refs.wp_post_id)
 * with a unified, provider-agnostic publication record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_publications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('content_id');
            $table->uuid('destination_id')->nullable()->comment('Links to content_destinations; null for legacy client_site references');
            $table->uuid('client_site_id')->nullable()->comment('Legacy client_site reference for backwards compatibility');

            // Provider identification
            $table->string('provider', 40)->default('wordpress')->comment('Provider type: wordpress, laravel, api, webhook');
            $table->string('remote_id', 255)->nullable()->comment('Remote system identifier (e.g., WordPress post ID)');
            $table->string('remote_type', 64)->nullable()->comment('Remote resource type (e.g., post, page, article)');
            $table->string('remote_url', 2048)->nullable()->comment('Published URL on the remote system');
            $table->string('remote_status', 32)->nullable()->comment('Remote publish status (draft, published, scheduled, trash)');

            // Delivery tracking
            $table->string('delivery_status', 32)->default('pending')->comment('pending, delivered, failed, missing_remote');
            $table->string('payload_checksum', 64)->nullable()->comment('SHA-256 of last delivered payload for change detection');

            // Verification and timing
            $table->timestamp('last_verified_at')->nullable()->comment('Last time remote existence was verified');
            $table->timestamp('last_delivered_at')->nullable()->comment('Last successful delivery timestamp');

            // Error tracking
            $table->timestamp('last_error_at')->nullable();
            $table->string('last_error_code', 64)->nullable()->comment('Error code or HTTP status');
            $table->text('last_error_message')->nullable();

            // Metadata for extensibility
            $table->json('meta')->nullable()->comment('Provider-specific metadata (language, SEO sync status, etc.)');

            $table->timestamps();

            // Indexes for common queries
            $table->index(['content_id', 'provider'], 'publications_content_provider_idx');
            $table->index(['content_id', 'destination_id'], 'publications_content_destination_idx');
            $table->index(['content_id', 'client_site_id'], 'publications_content_site_idx');
            $table->index(['remote_id', 'provider'], 'publications_remote_provider_idx');
            $table->index(['delivery_status', 'created_at'], 'publications_delivery_status_idx');
            $table->index(['last_delivered_at'], 'publications_last_delivered_idx');

            // Unique constraint: one publication per content + destination combo
            $table->unique(['content_id', 'destination_id'], 'publications_content_destination_unique');

            // Foreign keys
            $table->foreign('content_id', 'cp_content_fk')
                ->references('id')
                ->on('contents')
                ->cascadeOnDelete();

            $table->foreign('destination_id', 'cp_destination_fk')
                ->references('id')
                ->on('content_destinations')
                ->nullOnDelete();

            $table->foreign('client_site_id', 'cp_client_site_fk')
                ->references('id')
                ->on('client_sites')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_publications');
    }
};
