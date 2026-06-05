<?php

namespace App\Console\Commands;

use App\Enums\ContentAutomationMode;
use App\Enums\ContentOriginType;
use App\Models\Content;
use App\Models\ContentAutomationRun;
use App\Models\ContentChainSuggestion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillContentOriginCommand extends Command
{
    protected $signature = 'content:backfill-origin
        {--dry-run : Preview changes without applying}
        {--workspace= : Filter by workspace_id}';

    protected $description = 'Backfill origin_type and related fields for existing content records.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $workspaceId = trim((string) $this->option('workspace'));

        $this->info($dryRun ? 'DRY RUN - no changes will be made' : 'Backfilling content origin data...');
        $this->newLine();

        $stats = [
            'automation' => 0,
            'chained_via_automation' => 0,
            'chained' => 0,
            'series' => 0,
            'manual' => 0,
            'first_published_at' => 0,
            'skipped' => 0,
        ];

        // Step 1: Content from automation runs
        $this->info('Step 1: Processing content from automation runs...');
        ContentAutomationRun::query()
            ->with('automation')
            ->whereNotNull('generated_content_ids')
            ->where('generated_content_ids', '!=', '[]')
            ->when($workspaceId !== '', fn ($q) => $q->where('workspace_id', $workspaceId))
            ->orderBy('created_at')
            ->chunk(100, function ($runs) use ($dryRun, &$stats): void {
                foreach ($runs as $run) {
                    $contentIds = $run->generated_content_ids ?? [];
                    if (empty($contentIds)) {
                        continue;
                    }

                    $automation = $run->automation;
                    $isChainMode = $automation?->mode instanceof ContentAutomationMode
                        && in_array($automation->mode, [ContentAutomationMode::CHAIN, ContentAutomationMode::PILLAR_PLUS_CLUSTER], true);

                    $originType = $isChainMode
                        ? ContentOriginType::CHAINED_VIA_AUTOMATION
                        : ContentOriginType::AUTOMATION;

                    $query = Content::whereIn('id', $contentIds)
                        ->where('origin_type', ContentOriginType::UNKNOWN->value);

                    $count = $query->count();
                    $stats[$originType->value] += $count;

                    if (! $dryRun && $count > 0) {
                        $query->update([
                            'origin_type' => $originType->value,
                            'automation_id' => $run->automation_id,
                            'automation_run_id' => $run->id,
                        ]);
                    }
                }
            });

        // Step 2: Content from chain suggestions (not via automation)
        $this->info('Step 2: Processing content from chain suggestions...');
        ContentChainSuggestion::query()
            ->whereNotNull('generated_content_id')
            ->when($workspaceId !== '', fn ($q) => $q->where('workspace_id', $workspaceId))
            ->orderBy('created_at')
            ->chunk(100, function ($suggestions) use ($dryRun, &$stats): void {
                foreach ($suggestions as $suggestion) {
                    $query = Content::where('id', $suggestion->generated_content_id)
                        ->where('origin_type', ContentOriginType::UNKNOWN->value)
                        ->whereNull('automation_id');

                    $count = $query->count();
                    $stats['chained'] += $count;

                    if (! $dryRun && $count > 0) {
                        $query->update([
                            'origin_type' => ContentOriginType::CHAINED->value,
                            'source_chain_suggestion_id' => $suggestion->id,
                        ]);
                    }
                }
            });

        // Step 3: Content from series (not automation generated)
        $this->info('Step 3: Processing content from series...');
        $seriesQuery = Content::query()
            ->whereNotNull('series_id')
            ->where('origin_type', ContentOriginType::UNKNOWN->value)
            ->whereNull('automation_id')
            ->when($workspaceId !== '', fn ($q) => $q->where('workspace_id', $workspaceId));

        $seriesCount = $seriesQuery->count();
        $stats['series'] = $seriesCount;

        if (! $dryRun && $seriesCount > 0) {
            $seriesQuery->update(['origin_type' => ContentOriginType::SERIES_GENERATED->value]);
        }

        // Step 4: Manual content (source = 'manual', no automation)
        $this->info('Step 4: Processing manual content...');
        $manualQuery = Content::query()
            ->where('source', 'manual')
            ->where('origin_type', ContentOriginType::UNKNOWN->value)
            ->whereNull('automation_id')
            ->when($workspaceId !== '', fn ($q) => $q->where('workspace_id', $workspaceId));

        $manualCount = $manualQuery->count();
        $stats['manual'] = $manualCount;

        if (! $dryRun && $manualCount > 0) {
            $manualQuery->update(['origin_type' => ContentOriginType::MANUAL->value]);
        }

        // Step 5: Backfill first_published_at from ContentPublication
        $this->info('Step 5: Backfilling first_published_at from publications...');
        if (! $dryRun) {
            $driver = DB::getDriverName();
            if ($driver === 'mysql') {
                $affected = DB::statement("
                    UPDATE contents c
                    INNER JOIN (
                        SELECT content_id, MIN(last_delivered_at) as first_delivery
                        FROM content_publications
                        WHERE delivery_status = 'delivered'
                        AND last_delivered_at IS NOT NULL
                        GROUP BY content_id
                    ) pub ON pub.content_id = c.id
                    SET c.first_published_at = pub.first_delivery
                    WHERE c.first_published_at IS NULL
                ");
            } else {
                // SQLite/Postgres compatible version
                Content::query()
                    ->whereNull('first_published_at')
                    ->whereHas('publications', fn ($q) => $q->where('delivery_status', 'delivered')->whereNotNull('last_delivered_at'))
                    ->chunk(100, function ($contents) use (&$stats): void {
                        foreach ($contents as $content) {
                            $firstDelivery = $content->publications()
                                ->where('delivery_status', 'delivered')
                                ->whereNotNull('last_delivered_at')
                                ->min('last_delivered_at');

                            if ($firstDelivery) {
                                $content->update(['first_published_at' => $firstDelivery]);
                                $stats['first_published_at']++;
                            }
                        }
                    });
            }
        }

        // For dry-run, count what would be updated
        if ($dryRun) {
            $publishedCount = Content::query()
                ->whereNull('first_published_at')
                ->whereHas('publications', fn ($q) => $q->where('delivery_status', 'delivered')->whereNotNull('last_delivered_at'))
                ->when($workspaceId !== '', fn ($q) => $q->where('workspace_id', $workspaceId))
                ->count();
            $stats['first_published_at'] = $publishedCount;
        }

        // Count remaining unknown
        $remainingUnknown = Content::query()
            ->where('origin_type', ContentOriginType::UNKNOWN->value)
            ->when($workspaceId !== '', fn ($q) => $q->where('workspace_id', $workspaceId))
            ->count();
        $stats['skipped'] = $remainingUnknown;

        $this->newLine();
        $this->table(
            ['Origin Type', 'Count'],
            collect($stats)->map(fn ($count, $type) => [$type, $count])->values()->all()
        );

        $this->newLine();
        $this->info($dryRun ? 'Dry run complete. No changes were made.' : 'Backfill complete.');

        if ($remainingUnknown > 0) {
            $this->warn("$remainingUnknown content records remain with origin_type = 'unknown'.");
        }

        return self::SUCCESS;
    }
}
