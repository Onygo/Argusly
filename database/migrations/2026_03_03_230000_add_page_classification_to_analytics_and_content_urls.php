<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            if (! Schema::hasColumn('contents', 'publish_url_key')) {
                $table->string('publish_url_key', 512)->nullable()->after('published_url');
            }
            if (! Schema::hasColumn('contents', 'canonical_url_key')) {
                $table->string('canonical_url_key', 512)->nullable()->after('publish_url_key');
            }

            $table->index(['client_site_id', 'publish_url_key'], 'contents_site_publish_url_key_idx');
            $table->index(['client_site_id', 'canonical_url_key'], 'contents_site_canonical_url_key_idx');
        });

        Schema::table('analytics_events', function (Blueprint $table): void {
            if (! Schema::hasColumn('analytics_events', 'url_key')) {
                $table->string('url_key', 512)->nullable()->after('canonical_url');
            }
            if (! Schema::hasColumn('analytics_events', 'content_id')) {
                $table->uuid('content_id')->nullable()->after('article_id');
            }
            if (! Schema::hasColumn('analytics_events', 'page_type')) {
                $table->string('page_type', 32)->nullable()->after('content_id');
            }

            $table->index(['analytics_site_id', 'event_type', 'content_id', 'event_time'], 'analytics_events_scope_idx');
            $table->index(['analytics_site_id', 'url_key'], 'analytics_events_site_url_key_idx');
            $table->index('page_type', 'analytics_events_page_type_idx');
            $table->foreign('content_id')->references('id')->on('contents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('analytics_events', function (Blueprint $table): void {
            $table->dropForeign(['content_id']);
            $table->dropIndex('analytics_events_scope_idx');
            $table->dropIndex('analytics_events_site_url_key_idx');
            $table->dropIndex('analytics_events_page_type_idx');

            foreach (['url_key', 'content_id', 'page_type'] as $column) {
                if (Schema::hasColumn('analytics_events', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('contents', function (Blueprint $table): void {
            $table->dropIndex('contents_site_publish_url_key_idx');
            $table->dropIndex('contents_site_canonical_url_key_idx');

            foreach (['publish_url_key', 'canonical_url_key'] as $column) {
                if (Schema::hasColumn('contents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
