<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drafts - status column is heavily queried in scheduled jobs (every minute)
        // Note: delivery_status index already exists in 2026_01_31_122729
        Schema::table('drafts', function (Blueprint $table) {
            $table->index('status', 'drafts_status_idx');
            $table->index(['status', 'delivery_status'], 'drafts_status_delivery_idx');
        });

        // Briefs - queried by status in processing commands
        Schema::table('briefs', function (Blueprint $table) {
            $table->index('status', 'briefs_status_idx');
        });

        // Content Images - queried by status and type for image generation/retrieval
        // Note: content_images_content_type_active_idx already exists in 2026_02_23_190000
        Schema::table('content_images', function (Blueprint $table) {
            $table->index('status', 'content_images_status_idx');
            $table->index('type', 'content_images_type_idx');
        });

        // Link Suggestions - queried by status for link intelligence features
        Schema::table('link_suggestions', function (Blueprint $table) {
            $table->index('status', 'link_suggestions_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->dropIndex('drafts_status_idx');
            $table->dropIndex('drafts_status_delivery_idx');
        });

        Schema::table('briefs', function (Blueprint $table) {
            $table->dropIndex('briefs_status_idx');
        });

        Schema::table('content_images', function (Blueprint $table) {
            $table->dropIndex('content_images_status_idx');
            $table->dropIndex('content_images_type_idx');
        });

        Schema::table('link_suggestions', function (Blueprint $table) {
            $table->dropIndex('link_suggestions_status_idx');
        });
    }
};