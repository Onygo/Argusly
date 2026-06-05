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
            $table->text('last_failure_message')->nullable()->after('paused_at');
            $table->string('last_failure_code', 128)->nullable()->after('last_failure_message');
            $table->uuid('last_failure_run_id')->nullable()->after('last_failure_code');
            $table->timestamp('last_failure_at')->nullable()->after('last_failure_run_id');
        });

        Schema::table('content_automation_runs', function (Blueprint $table): void {
            $table->unsignedInteger('attempt_count')->default(0)->after('triggered_by');
            $table->timestamp('last_attempt_at')->nullable()->after('attempt_count');
        });

        $duplicates = DB::table('content_automation_run_items')
            ->select('automation_run_id', 'item_type', 'chain_index', 'locale', DB::raw('COUNT(*) as duplicate_count'))
            ->groupBy('automation_run_id', 'item_type', 'chain_index', 'locale')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $items = DB::table('content_automation_run_items')
                ->where('automation_run_id', $duplicate->automation_run_id)
                ->where('item_type', $duplicate->item_type)
                ->where('chain_index', $duplicate->chain_index)
                ->where('locale', $duplicate->locale)
                ->orderByRaw('CASE WHEN content_id IS NULL THEN 1 ELSE 0 END')
                ->orderByRaw('CASE WHEN draft_id IS NULL THEN 1 ELSE 0 END')
                ->orderBy('created_at')
                ->get();

            $keeper = $items->shift();
            if (! $keeper) {
                continue;
            }

            foreach ($items as $extra) {
                DB::table('content_automation_run_items')
                    ->where('id', $extra->id)
                    ->delete();
            }
        }

        Schema::table('content_automation_run_items', function (Blueprint $table): void {
            $table->dropIndex('automation_run_items_run_locale_idx');
            $table->unique(
                ['automation_run_id', 'item_type', 'chain_index', 'locale'],
                'automation_run_items_run_type_chain_locale_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('content_automation_run_items', function (Blueprint $table): void {
            $table->dropUnique('automation_run_items_run_type_chain_locale_unique');
            $table->index(['automation_run_id', 'locale'], 'automation_run_items_run_locale_idx');
        });

        Schema::table('content_automation_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'attempt_count',
                'last_attempt_at',
            ]);
        });

        Schema::table('content_automations', function (Blueprint $table): void {
            $table->dropColumn([
                'last_failure_message',
                'last_failure_code',
                'last_failure_run_id',
                'last_failure_at',
            ]);
        });
    }
};
