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
            if (! Schema::hasColumn('contents', 'client_site_id')) {
                $table->uuid('client_site_id')->nullable()->after('workspace_id');
            }
            if (! Schema::hasColumn('contents', 'external_key')) {
                $table->string('external_key')->nullable()->after('external_id');
            }
            if (! Schema::hasColumn('contents', 'wp_post_id')) {
                $table->string('wp_post_id')->nullable()->after('external_key');
            }
            if (! Schema::hasColumn('contents', 'current_version_id')) {
                $table->uuid('current_version_id')->nullable()->after('current_revision_id');
            }
            if (! Schema::hasColumn('contents', 'primary_keyword')) {
                $table->string('primary_keyword')->nullable()->after('title');
            }
            if (! Schema::hasColumn('contents', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('last_feedback_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('contents', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `contents` MODIFY `status` ENUM('brief_received','brief','draft','review','approved','published','archived') NOT NULL DEFAULT 'brief'");
        }

        Schema::table('contents', function (Blueprint $table) {
            $table->index('wp_post_id', 'contents_wp_post_idx');
            $table->unique(['client_site_id', 'external_key'], 'contents_client_site_external_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropUnique('contents_client_site_external_key_unique');
            $table->dropIndex('contents_wp_post_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `contents` MODIFY `status` ENUM('brief_received','draft','review','approved','published') NOT NULL DEFAULT 'brief_received'");
        }

        Schema::table('contents', function (Blueprint $table) {
            if (Schema::hasColumn('contents', 'updated_by')) {
                $table->dropConstrainedForeignId('updated_by');
            }
            if (Schema::hasColumn('contents', 'created_by')) {
                $table->dropConstrainedForeignId('created_by');
            }
            if (Schema::hasColumn('contents', 'primary_keyword')) {
                $table->dropColumn('primary_keyword');
            }
            if (Schema::hasColumn('contents', 'current_version_id')) {
                $table->dropColumn('current_version_id');
            }
            if (Schema::hasColumn('contents', 'wp_post_id')) {
                $table->dropColumn('wp_post_id');
            }
            if (Schema::hasColumn('contents', 'external_key')) {
                $table->dropColumn('external_key');
            }
            if (Schema::hasColumn('contents', 'client_site_id')) {
                $table->dropColumn('client_site_id');
            }
        });
    }
};
