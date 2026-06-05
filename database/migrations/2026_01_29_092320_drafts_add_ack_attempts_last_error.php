<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        });

        // Index alleen toevoegen als hij nog niet bestaat (driver-safe voor tests op sqlite).
        if (! $this->hasDraftsClientSiteStatusIndex()) {
            Schema::table('drafts', function (Blueprint $table) {
                $table->index(['client_site_id', 'status'], 'drafts_client_site_status_idx');
            });
        }
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
        });

        // Index alleen droppen als hij bestaat (driver-safe voor tests op sqlite).
        if ($this->hasDraftsClientSiteStatusIndex()) {
            Schema::table('drafts', function (Blueprint $table) {
                $table->dropIndex('drafts_client_site_status_idx');
            });
        }
    }

    private function hasDraftsClientSiteStatusIndex(): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('drafts')");

            foreach ($indexes as $index) {
                $name = is_array($index) ? ($index['name'] ?? null) : ($index->name ?? null);

                if ($name === 'drafts_client_site_status_idx') {
                    return true;
                }
            }

            return false;
        }

        $indexes = DB::select("SHOW INDEX FROM drafts WHERE Key_name = 'drafts_client_site_status_idx'");

        return ! empty($indexes);
    }
};
