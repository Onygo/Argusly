<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            if (! Schema::hasColumn('contents', 'content_destination_id')) {
                $table->uuid('content_destination_id')->nullable()->after('client_site_id');
                $table->index(['content_destination_id'], 'contents_destination_idx');
                $table->foreign('content_destination_id')->references('id')->on('content_destinations')->nullOnDelete();
            }
        });

        Schema::table('briefs', function (Blueprint $table) {
            if (! Schema::hasColumn('briefs', 'content_destination_id')) {
                $table->uuid('content_destination_id')->nullable()->after('client_site_id');
                $table->index(['content_destination_id'], 'briefs_destination_idx');
                $table->foreign('content_destination_id')->references('id')->on('content_destinations')->nullOnDelete();
            }
        });

        Schema::table('drafts', function (Blueprint $table) {
            if (! Schema::hasColumn('drafts', 'content_destination_id')) {
                $table->uuid('content_destination_id')->nullable()->after('client_site_id');
                $table->index(['content_destination_id'], 'drafts_destination_idx');
                $table->foreign('content_destination_id')->references('id')->on('content_destinations')->nullOnDelete();
            }
        });

        Schema::table('seo_audits', function (Blueprint $table) {
            if (! Schema::hasColumn('seo_audits', 'content_destination_id')) {
                $table->uuid('content_destination_id')->nullable()->after('client_site_id');
                $table->index(['content_destination_id'], 'seo_audits_destination_idx');
                $table->foreign('content_destination_id')->references('id')->on('content_destinations')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('seo_audits', function (Blueprint $table) {
            if (Schema::hasColumn('seo_audits', 'content_destination_id')) {
                $table->dropForeign(['content_destination_id']);
                $table->dropIndex('seo_audits_destination_idx');
                $table->dropColumn('content_destination_id');
            }
        });

        Schema::table('drafts', function (Blueprint $table) {
            if (Schema::hasColumn('drafts', 'content_destination_id')) {
                $table->dropForeign(['content_destination_id']);
                $table->dropIndex('drafts_destination_idx');
                $table->dropColumn('content_destination_id');
            }
        });

        Schema::table('briefs', function (Blueprint $table) {
            if (Schema::hasColumn('briefs', 'content_destination_id')) {
                $table->dropForeign(['content_destination_id']);
                $table->dropIndex('briefs_destination_idx');
                $table->dropColumn('content_destination_id');
            }
        });

        Schema::table('contents', function (Blueprint $table) {
            if (Schema::hasColumn('contents', 'content_destination_id')) {
                $table->dropForeign(['content_destination_id']);
                $table->dropIndex('contents_destination_idx');
                $table->dropColumn('content_destination_id');
            }
        });
    }
};
