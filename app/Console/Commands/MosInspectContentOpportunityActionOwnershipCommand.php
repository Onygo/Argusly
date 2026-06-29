<?php

namespace App\Console\Commands;

use App\Models\ContentOpportunity;
use App\Services\Mos\Opportunity\ContentOpportunityCanonicalActionOwnershipResolver;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class MosInspectContentOpportunityActionOwnershipCommand extends Command
{
    protected $signature = 'mos:inspect-content-opportunity-action-ownership
        {--workspace= : Limit to a workspace id}
        {--site= : Limit to a client site id}
        {--source-id= : Inspect one ContentOpportunity id}
        {--status= : Limit to a legacy content opportunity status}
        {--limit=100 : Maximum records to inspect}';

    protected $description = 'Read-only inspection of canonical action ownership readiness for linked ContentOpportunity recommended actions.';

    public function handle(ContentOpportunityCanonicalActionOwnershipResolver $resolver): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $summary = [
            'legacy' => 0,
            'canonical-ready' => 0,
            'canonical-active' => 0,
            'blocked' => 0,
        ];
        $rows = [];

        $this->components->info('Read-only action ownership inspection. No recommended actions, source links, lifecycle statuses, routes, briefs or queues will be changed.');
        $this->line('Feature flag mos_canonical_content_opportunity_action_ownership: '.(config('features.mos_canonical_content_opportunity_action_ownership') ? 'enabled' : 'disabled'));

        $this->query()
            ->limit($limit)
            ->get()
            ->each(function (ContentOpportunity $opportunity) use ($resolver, &$summary, &$rows): void {
                $result = $resolver->resolve($opportunity);
                $status = (string) $result['ownership_status'];

                if (array_key_exists($status, $summary)) {
                    $summary[$status]++;
                }

                $rows[] = [
                    $result['legacy_source_id'],
                    $result['canonical_owner_id'] ?? 'missing',
                    $opportunity->workspace_id ?? 'missing',
                    $opportunity->client_site_id ?? 'missing',
                    $status,
                    $result['duplicate_metadata_status'],
                    $result['primary_recommended_action_id'] ?? 'none',
                    implode(', ', $result['duplicate_recommended_action_ids']),
                    $result['cta_route'],
                    $result['fallback_route'],
                    implode(', ', $result['blocked_reasons']),
                ];
            });

        $this->newLine();
        $this->table(
            ['legacy-owned actions', 'canonical-ready actions', 'canonical-active actions', 'blocked actions'],
            [[$summary['legacy'], $summary['canonical-ready'], $summary['canonical-active'], $summary['blocked']]],
        );

        if ($rows !== []) {
            $this->newLine();
            $this->table(
                ['legacy id', 'canonical id', 'workspace id', 'site id', 'ownership status', 'duplicate metadata', 'primary action id', 'duplicate action ids', 'proposed CTA route', 'fallback route', 'blocked reasons'],
                $rows,
            );
        }

        return self::SUCCESS;
    }

    /**
     * @return Builder<ContentOpportunity>
     */
    private function query(): Builder
    {
        return ContentOpportunity::query()
            ->with(['workspace', 'site'])
            ->when($this->option('workspace'), fn (Builder $query, string $workspace): Builder => $query->where('workspace_id', $workspace))
            ->when($this->option('site'), fn (Builder $query, string $site): Builder => $query->where('client_site_id', $site))
            ->when($this->option('source-id'), fn (Builder $query, string $sourceId): Builder => $query->whereKey($sourceId))
            ->when($this->option('status'), fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->orderByDesc('priority_score')
            ->orderBy('id');
    }
}
