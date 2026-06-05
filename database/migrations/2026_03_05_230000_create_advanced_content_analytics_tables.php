<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_scroll_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('analytics_site_id');
            $table->string('url', 2000);
            $table->string('url_key', 512);
            $table->string('session_id', 128);
            $table->unsignedTinyInteger('depth');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['analytics_site_id', 'url_key'], 'page_scroll_events_site_url_key_idx');
            $table->index('created_at', 'page_scroll_events_created_at_idx');
            $table->unique(
                ['analytics_site_id', 'url_key', 'session_id', 'depth'],
                'page_scroll_events_site_url_session_depth_unique'
            );

            $table->foreign('analytics_site_id')
                ->references('id')
                ->on('analytics_sites')
                ->cascadeOnDelete();
        });

        Schema::create('page_read_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('analytics_site_id');
            $table->string('url', 2000);
            $table->string('url_key', 512);
            $table->string('session_id', 128);
            $table->unsignedInteger('read_seconds');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['analytics_site_id', 'url_key'], 'page_read_sessions_site_url_key_idx');
            $table->index('created_at', 'page_read_sessions_created_at_idx');
            $table->unique(
                ['analytics_site_id', 'url_key', 'session_id'],
                'page_read_sessions_site_url_session_unique'
            );

            $table->foreign('analytics_site_id')
                ->references('id')
                ->on('analytics_sites')
                ->cascadeOnDelete();
        });

        Schema::create('content_metrics', function (Blueprint $table): void {
            $table->id();
            $table->uuid('analytics_site_id');
            $table->string('url', 2000);
            $table->string('url_key', 512);
            $table->decimal('avg_scroll_depth', 6, 2)->default(0);
            $table->unsignedTinyInteger('max_scroll_depth')->default(0);
            $table->decimal('avg_read_time', 10, 2)->default(0);
            $table->decimal('median_read_time', 10, 2)->default(0);
            $table->decimal('engaged_rate', 8, 4)->default(0);
            $table->decimal('read_through_rate', 8, 4)->default(0);
            $table->decimal('estimated_read_time', 10, 2)->default(0);
            $table->decimal('roi_score', 8, 2)->default(0);
            $table->json('conversion_signals')->nullable();
            $table->json('attribution_signals')->nullable();
            $table->json('ai_traffic_signals')->nullable();
            $table->timestamps();

            $table->unique(['analytics_site_id', 'url_key'], 'content_metrics_site_url_key_unique');
            $table->index(['analytics_site_id', 'roi_score'], 'content_metrics_site_roi_idx');
            $table->foreign('analytics_site_id')
                ->references('id')
                ->on('analytics_sites')
                ->cascadeOnDelete();
        });

        Schema::create('content_ai_visibility', function (Blueprint $table): void {
            $table->id();
            $table->uuid('analytics_site_id');
            $table->string('url', 2000);
            $table->string('url_key', 512);
            $table->unsignedInteger('llm_citations')->default(0);
            $table->unsignedInteger('brand_mentions')->default(0);
            $table->unsignedInteger('competitor_mentions')->default(0);
            $table->decimal('ai_visibility_score', 12, 2)->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['analytics_site_id', 'url_key'], 'content_ai_visibility_site_url_key_unique');
            $table->index(['analytics_site_id', 'ai_visibility_score'], 'content_ai_visibility_site_score_idx');
            $table->foreign('analytics_site_id')
                ->references('id')
                ->on('analytics_sites')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_ai_visibility');
        Schema::dropIfExists('content_metrics');
        Schema::dropIfExists('page_read_sessions');
        Schema::dropIfExists('page_scroll_events');
    }
};
