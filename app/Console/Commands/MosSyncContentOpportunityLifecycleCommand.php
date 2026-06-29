<?php

namespace App\Console\Commands;

use App\Models\ContentOpportunity;
use App\Models\Opportunity;
use App\Services\Mos\Opportunity\ContentOpportunityCanonicalLifecycleSyncResult;
use App\Services\Mos\Opportunity\ContentOpportunityCanonicalLifecycleSyncService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class MosSyncContentOpportunityLifecycleCommand extends Command
{
    protected $signature = 'mos:sync-content-opportunity-lifecycle
        {--apply : Apply safe lifecycle updates}
        {--direction=legacy-to-canonical : Sync direction: legacy-to-canonical or canonical-to-legacy}
        {--workspace= : Limit to a workspace id}
        {--site= : Limit to a client site id}
        {--source-id= : Inspect one ContentOpportunity id}
        {--status= : Limit to the source-side status for the selected direction}
        {--limit=100 : Maximum records to inspect}';

    protected $description = 'Dry-run-first sync of linked ContentOpportunity and canonical Opportunity lifecycle status.';

    public function handle(ContentOpportunityCanonicalLifecycleSyncService $sync): int
    {
        $direction = strtolower(trim((string) $this->option('direction')));

        if (! in_array($direction, $sync->directions(), true)) {
            $this->components->error('Unsupported direction. Use legacy-to-canonical or canonical-to-legacy.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $limit = max(1, (int) $this->option('limit'));
        $summary = [
            'seen' => 0,
            'aligned' => 0,
            'would_update' => 0,
            'updated' => 0,
            'conflicts' => 0,
            'unmapped' => 0,
            'blocked_canonical_only_states' => 0,
            'missing_links' => 0,
            'skipped' => 0,
        ];
        $rows = [];

        $this->components->info(($apply ? 'Apply mode' : 'Dry-run mode')." lifecycle sync ({$direction}).");

        $this->query($direction)
            ->limit($limit)
            ->get()
            ->each(function (ContentOpportunity $legacy) use ($sync, $direction, $apply, &$summary, &$rows): void {
                $canonical = Opportunity::query()
                    ->where('content_opportunity_id', $legacy->id)
                    ->where('workspace_id', $legacy->workspace_id)
                    ->where('organization_id', $legacy->organization_id)
                    ->oldest()
                    ->first();

                $result = $sync->sync($legacy, $canonical, $direction, $apply);

                $summary['seen']++;
                $this->count($summary, $result);

                if ($result->status !== 'aligned' || $result->conflict || $result->blockedReasons !== []) {
                    $rows[] = [
                        $result->legacyContentOpportunityId,
                        $result->canonicalOpportunityId ?? 'missing',
                        $result->legacyStatus ?? 'missing',
                        $result->canonicalStatus ?? 'missing',
                        $result->desiredLegacyStatus ?? $result->desiredCanonicalStatus ?? 'none',
                        $result->status,
                        implode(',', $result->blockedReasons),
                    ];
                }
            });

        $this->newLine();
        $this->line(sprintf(
            'Diagnostics: aligned rows: %d; would-update rows: %d; conflicts: %d; unmapped statuses: %d; blocked canonical-only states: %d; missing links: %d; skipped rows: %d.',
            $summary['aligned'],
            $summary['would_update'],
            $summary['conflicts'],
            $summary['unmapped'],
            $summary['blocked_canonical_only_states'],
            $summary['missing_links'],
            $summary['skipped'],
        ));

        $this->newLine();
        $this->table([
            'seen',
            'aligned rows',
            'would-update rows',
            'updated rows',
            'conflicts',
            'unmapped statuses',
            'blocked canonical-only states',
            'missing links',
            'skipped rows',
        ], [[
            $summary['seen'],
            $summary['aligned'],
            $summary['would_update'],
            $summary['updated'],
            $summary['conflicts'],
            $summary['unmapped'],
            $summary['blocked_canonical_only_states'],
            $summary['missing_links'],
            $summary['skipped'],
        ]]);

        if ($rows !== []) {
            $this->newLine();
            $this->table(['legacy id', 'canonical id', 'legacy', 'canonical', 'desired', 'state', 'blocked reasons'], $rows);
        }

        $this->newLine();
        $this->line("conflicts: {$summary['conflicts']}");

        return self::SUCCESS;
    }

    /**
     * @return Builder<ContentOpportunity>
     */
    private function query(string $direction): Builder
    {
        return ContentOpportunity::query()
            ->when($this->option('workspace'), fn (Builder $query, string $workspace): Builder => $query->where('workspace_id', $workspace))
            ->when($this->option('site'), fn (Builder $query, string $site): Builder => $query->where('client_site_id', $site))
            ->when($this->option('source-id'), fn (Builder $query, string $sourceId): Builder => $query->whereKey($sourceId))
            ->when($this->option('status'), function (Builder $query, string $status) use ($direction): Builder {
                if ($direction === ContentOpportunityCanonicalLifecycleSyncService::DIRECTION_LEGACY_TO_CANONICAL) {
                    return $query->where('status', $status);
                }

                return $query->whereHas('canonicalOpportunities', fn (Builder $query): Builder => $query->where('status', $status));
            })
            ->orderByDesc('priority_score')
            ->orderBy('id');
    }

    /**
     * @param  array<string, int>  $summary
     */
    private function count(array &$summary, ContentOpportunityCanonicalLifecycleSyncResult $result): void
    {
        if ($result->status === 'aligned') {
            $summary['aligned']++;
        }

        if ($result->status === 'would_update') {
            $summary['would_update']++;
        }

        if ($result->status === 'updated') {
            $summary['updated']++;
        }

        if ($result->conflict) {
            $summary['conflicts']++;
        }

        if ($result->unmappedLegacyStatus || ($result->unmappedCanonicalStatus && ! in_array('blocked_canonical_only_status', $result->blockedReasons, true))) {
            $summary['unmapped']++;
        }

        if (in_array('blocked_canonical_only_status', $result->blockedReasons, true)) {
            $summary['blocked_canonical_only_states']++;
        }

        if ($result->missingCanonicalLink) {
            $summary['missing_links']++;
        }

        if (! $result->safe) {
            $summary['skipped']++;
        }
    }
}
