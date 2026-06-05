<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_automations', function (Blueprint $table): void {
            if (! Schema::hasColumn('content_automations', 'end_at')) {
                $table->timestamp('end_at')->nullable()->after('last_run_at');
            }

            if (! Schema::hasColumn('content_automations', 'max_runs')) {
                $table->unsignedInteger('max_runs')->nullable()->after('end_at');
            }

            if (! Schema::hasColumn('content_automations', 'run_count')) {
                $table->unsignedInteger('run_count')->default(0)->after('max_runs');
            }

            if (! Schema::hasColumn('content_automations', 'is_paused')) {
                $table->boolean('is_paused')->default(false)->after('is_active');
            }

            if (! Schema::hasColumn('content_automations', 'paused_at')) {
                $table->timestamp('paused_at')->nullable()->after('updated_by');
            }
        });

        if (Schema::hasColumn('content_automations', 'is_paused') && Schema::hasColumn('content_automations', 'paused_at')) {
            DB::table('content_automations')
                ->whereNotNull('paused_at')
                ->update(['is_paused' => true]);
        }

        if (Schema::hasColumn('content_automations', 'max_runs')
            && Schema::hasColumn('content_automations', 'run_count')
            && Schema::hasColumn('content_automations', 'is_paused')
            && Schema::hasColumn('content_automations', 'paused_at')) {
            DB::table('content_automations')
                ->whereNotNull('max_runs')
                ->whereColumn('run_count', '>=', 'max_runs')
                ->update([
                    'is_paused' => true,
                    'paused_at' => DB::raw('COALESCE(paused_at, CURRENT_TIMESTAMP)'),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('content_automations', function (Blueprint $table): void {
            foreach (['end_at', 'max_runs', 'run_count', 'is_paused'] as $column) {
                if (Schema::hasColumn('content_automations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
