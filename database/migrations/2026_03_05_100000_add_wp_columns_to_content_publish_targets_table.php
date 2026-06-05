<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_publish_targets', function (Blueprint $table): void {
            if (! Schema::hasColumn('content_publish_targets', 'wp_post_id')) {
                $table->string('wp_post_id')->nullable()->after('target_identifier');
            }

            if (! Schema::hasColumn('content_publish_targets', 'wp_featured_media_id')) {
                $table->string('wp_featured_media_id')->nullable()->after('wp_post_id');
            }
        });

        Schema::table('content_publish_targets', function (Blueprint $table): void {
            $table->index('wp_post_id', 'content_publish_targets_wp_post_idx');
            $table->index('wp_featured_media_id', 'content_publish_targets_wp_featured_media_idx');
        });
    }

    public function down(): void
    {
        Schema::table('content_publish_targets', function (Blueprint $table): void {
            $table->dropIndex('content_publish_targets_wp_post_idx');
            $table->dropIndex('content_publish_targets_wp_featured_media_idx');

            if (Schema::hasColumn('content_publish_targets', 'wp_featured_media_id')) {
                $table->dropColumn('wp_featured_media_id');
            }

            if (Schema::hasColumn('content_publish_targets', 'wp_post_id')) {
                $table->dropColumn('wp_post_id');
            }
        });
    }
};

