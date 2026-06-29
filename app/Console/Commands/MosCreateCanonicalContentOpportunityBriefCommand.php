<?php

namespace App\Console\Commands;

use App\Models\ClientSite;
use App\Models\ContentOpportunity;
use App\Models\Opportunity;
use App\Services\Mos\Opportunity\ContentOpportunityCanonicalBriefWriter;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class MosCreateCanonicalContentOpportunityBriefCommand extends Command
{
    protected $signature = 'mos:create-canonical-content-opportunity-brief
        {--apply : Create safe briefs instead of reporting dry-run output}
        {--workspace= : Limit to a workspace id}
        {--site= : Limit to a client site id}
        {--source-id= : Inspect one ContentOpportunity id}
        {--opportunity-id= : Inspect one canonical Opportunity id}
        {--mode=single : Brief mode: single or chained}
        {--limit=100 : Maximum records to inspect}
        {--mark-planned : Explicitly mark linked legacy and canonical opportunities planned on apply}';

    protected $description = 'Dry-run first guarded writer for canonical ContentOpportunity brief creation.';

    public function handle(ContentOpportunityCanonicalBriefWriter $writer): int
    {
        $apply = (bool) $this->option('apply');
        $mode = in_array($this->option('mode'), ['single', 'chained'], true) ? (string) $this->option('mode') : 'single';
        $limit = max(1, (int) $this->option('limit'));
        $summary = [
            'would_create' => 0,
            'created' => 0,
            'blocked' => 0,
            'duplicates' => 0,
        ];
        $rows = [];

        $this->components->info($apply
            ? 'Apply mode for safe canonical content opportunity brief records.'
            : 'Dry-run mode. No briefs, statuses, routes, queues, or UI state will be changed.');

        $this->query()
            ->limit($limit)
            ->get()
            ->each(function (ContentOpportunity $contentOpportunity) use ($writer, $apply, $mode, &$summary, &$rows): void {
                $canonical = $this->canonicalFor($contentOpportunity);
                $site = $this->siteFor($contentOpportunity);
                $result = $apply && $canonical && $site
                    ? $writer->apply(
                        $contentOpportunity,
                        $canonical,
                        $site,
                        $mode,
                        null,
                        (bool) $this->option('mark-planned'),
                    )
                    : $writer->dryRun($contentOpportunity, $canonical, $site, $mode);

                $summary[$result->status] = ($summary[$result->status] ?? 0) + 1;

                if ($result->duplicateRisk) {
                    $summary['duplicates']++;
                }

                $rows[] = [
                    $result->status,
                    $result->clientSiteId ?? 'missing',
                    $result->canonicalOpportunityId ?? 'missing',
                    $result->legacyContentOpportunityId,
                    $result->payload['title'] ?? 'missing',
                    $result->payload['primary_keyword'] ?? 'missing',
                    implode(', ', $result->missingFields) ?: '-',
                    implode(', ', $result->blockedReasons) ?: '-',
                    $result->duplicateRisk ? ($result->duplicateBrief?->id ? (string) $result->duplicateBrief->id : 'yes') : 'no',
                ];
            });

        $this->newLine();
        $this->line('would-create brief: '.$summary['would_create']);
        $this->line('created briefs: '.$summary['created']);
        $this->line('blocked records: '.$summary['blocked']);
        $this->line('duplicate brief risk: '.$summary['duplicates']);

        if ($rows !== []) {
            $this->newLine();
            $this->table(
                [
                    'status',
                    'target site',
                    'canonical opportunity id',
                    'legacy content opportunity id',
                    'title',
                    'keyword',
                    'missing fields',
                    'blocked reasons',
                    'duplicate brief risk',
                ],
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
            ->whereHas('workspace')
            ->when($this->option('workspace'), fn (Builder $query, string $workspace): Builder => $query->where('workspace_id', $workspace))
            ->when($this->option('site'), fn (Builder $query, string $site): Builder => $query->where('client_site_id', $site))
            ->when($this->option('source-id'), fn (Builder $query, string $sourceId): Builder => $query->whereKey($sourceId))
            ->when($this->option('opportunity-id'), function (Builder $query, string $opportunityId): Builder {
                return $query->whereIn('id', Opportunity::query()
                    ->select('content_opportunity_id')
                    ->whereKey($opportunityId));
            })
            ->orderByDesc('priority_score')
            ->orderBy('id');
    }

    private function canonicalFor(ContentOpportunity $contentOpportunity): ?Opportunity
    {
        if ($this->option('opportunity-id')) {
            return Opportunity::query()
                ->whereKey((string) $this->option('opportunity-id'))
                ->first();
        }

        return Opportunity::query()
            ->where('content_opportunity_id', $contentOpportunity->id)
            ->first();
    }

    private function siteFor(ContentOpportunity $contentOpportunity): ?ClientSite
    {
        if ($this->option('site')) {
            return ClientSite::query()
                ->whereKey((string) $this->option('site'))
                ->first();
        }

        return $contentOpportunity->site;
    }
}
