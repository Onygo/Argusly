<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_publish_targets', function (Blueprint $table): void {
            if (! Schema::hasColumn('content_publish_targets', 'seo_sync_status')) {
                $table->string('seo_sync_status', 32)->nullable()->after('sync_status');
            }

            if (! Schema::hasColumn('content_publish_targets', 'seo_synced_at')) {
                $table->timestamp('seo_synced_at')->nullable()->after('last_synced_at');
            }

            if (! Schema::hasColumn('content_publish_targets', 'seo_sync_mode')) {
                $table->string('seo_sync_mode', 32)->nullable()->after('seo_sync_status');
            }

            if (! Schema::hasColumn('content_publish_targets', 'seo_sync_error')) {
                $table->text('seo_sync_error')->nullable()->after('seo_sync_mode');
            }

            if (! Schema::hasColumn('content_publish_targets', 'seo_synced_fields')) {
                $table->json('seo_synced_fields')->nullable()->after('seo_sync_error');
            }
        });

        Schema::table('content_publish_targets', function (Blueprint $table): void {
            $table->index(['seo_sync_status', 'updated_at'], 'content_publish_targets_seo_status_updated_idx');
            $table->index(['seo_sync_mode', 'updated_at'], 'content_publish_targets_seo_mode_updated_idx');
            $table->index(['seo_synced_at'], 'content_publish_targets_seo_synced_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('content_publish_targets', function (Blueprint $table): void {
            $table->dropIndex('content_publish_targets_seo_status_updated_idx');
            $table->dropIndex('content_publish_targets_seo_mode_updated_idx');
            $table->dropIndex('content_publish_targets_seo_synced_at_idx');

            if (Schema::hasColumn('content_publish_targets', 'seo_synced_fields')) {
                $table->dropColumn('seo_synced_fields');
            }

            if (Schema::hasColumn('content_publish_targets', 'seo_sync_error')) {
                $table->dropColumn('seo_sync_error');
            }

            if (Schema::hasColumn('content_publish_targets', 'seo_sync_mode')) {
                $table->dropColumn('seo_sync_mode');
            }

            if (Schema::hasColumn('content_publish_targets', 'seo_synced_at')) {
                $table->dropColumn('seo_synced_at');
            }

            if (Schema::hasColumn('content_publish_targets', 'seo_sync_status')) {
                $table->dropColumn('seo_sync_status');
            }
        });
    }
};
