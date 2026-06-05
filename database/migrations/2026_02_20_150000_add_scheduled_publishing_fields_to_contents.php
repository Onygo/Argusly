<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            if (! Schema::hasColumn('contents', 'scheduled_publish_at')) {
                $table->timestamp('scheduled_publish_at')->nullable()->after('delivery_status');
            }
            if (! Schema::hasColumn('contents', 'publish_status')) {
                $table->string('publish_status', 32)->default('draft')->after('scheduled_publish_at');
            }
            if (! Schema::hasColumn('contents', 'publish_error')) {
                $table->text('publish_error')->nullable()->after('publish_status');
            }
            if (! Schema::hasColumn('contents', 'published_url')) {
                $table->string('published_url', 2048)->nullable()->after('publish_error');
            }
        });

        DB::table('contents')
            ->where('status', 'published')
            ->update([
                'publish_status' => 'published',
            ]);

        Schema::table('contents', function (Blueprint $table) {
            $table->index(['client_site_id', 'publish_status', 'scheduled_publish_at'], 'contents_site_publish_schedule_idx');
            $table->index(['publish_status'], 'contents_publish_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            if (Schema::hasColumn('contents', 'publish_status')) {
                $table->dropIndex('contents_publish_status_idx');
            }
            if (Schema::hasColumn('contents', 'scheduled_publish_at')) {
                $table->dropIndex('contents_site_publish_schedule_idx');
            }

            foreach (['scheduled_publish_at', 'publish_status', 'publish_error', 'published_url'] as $column) {
                if (Schema::hasColumn('contents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
