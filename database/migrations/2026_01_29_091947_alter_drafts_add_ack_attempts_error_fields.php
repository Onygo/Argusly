<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drafts', function (Blueprint $table) {

            if (!Schema::hasColumn('drafts', 'attempts')) {
                $table->unsignedInteger('attempts')->default(0)->after('status');
            }

            if (!Schema::hasColumn('drafts', 'acked_at')) {
                $table->timestamp('acked_at')->nullable()->after('delivered_at');
            }

            if (!Schema::hasColumn('drafts', 'last_error')) {
                $table->text('last_error')->nullable()->after('acked_at');
            }

            // Performance index for workers and dashboards
            // Safe even if you already have single indexes on the columns
            $table->index(['client_site_id', 'status'], 'drafts_client_site_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('drafts', function (Blueprint $table) {

            if (Schema::hasColumn('drafts', 'attempts')) {
                $table->dropColumn('attempts');
            }

            if (Schema::hasColumn('drafts', 'acked_at')) {
                $table->dropColumn('acked_at');
            }

            if (Schema::hasColumn('drafts', 'last_error')) {
                $table->dropColumn('last_error');
            }

            $table->dropIndex('drafts_client_site_status_idx');
        });
    }
};
