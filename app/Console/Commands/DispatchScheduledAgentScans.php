<?php

namespace App\Console\Commands;

use App\Jobs\Agents\ScanSiteForLocalizationIssues;
use App\Jobs\Agents\ScanSiteForRefreshOpportunities;
use App\Models\ClientSite;
use Illuminate\Console\Command;

class DispatchScheduledAgentScans extends Command
{
    protected $signature = 'agents:dispatch-scheduled-scans
        {--organization= : Limit scans to one organization id}
        {--workspace= : Limit scans to one workspace id}
        {--site= : Limit scans to one site id}
        {--locale= : Limit content selection to one locale}
        {--status=* : Limit content selection to one or more statuses}
        {--recent-days= : Limit scans to recently updated content}
        {--site-limit=10 : Maximum number of sites to dispatch in one run}
        {--content-limit=25 : Maximum number of content items per site scan}
        {--queue=default : Queue name for scheduled scans}';

    protected $description = 'Dispatch bounded scheduled site scans for refresh and localization recommendations.';

    public function handle(): int
    {
        $organizationId = $this->optionAsInt('organization');
        $workspaceId = $this->optionAsString('workspace');
        $siteId = $this->optionAsString('site');
        $locale = $this->optionAsString('locale');
        $recentDays = $this->optionAsInt('recent-days');
        $siteLimit = max(1, (int) ($this->option('site-limit') ?: 10));
        $contentLimit = max(1, (int) ($this->option('content-limit') ?: 25));
        $queue = trim((string) ($this->option('queue') ?: 'default'));
        $statuses = collect((array) $this->option('status'))
            ->map(fn (mixed $status): string => trim((string) $status))
            ->filter()
            ->values()
            ->all();

        $sites = ClientSite::query()
            ->with('workspace')
            ->where('is_active', true)
            ->where('status', '!=', 'disabled')
            ->when($siteId !== null, fn ($query) => $query->whereKey($siteId))
            ->when($workspaceId !== null, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->when($organizationId !== null, fn ($query) => $query->whereHas('workspace', fn ($workspaceQuery) => $workspaceQuery->where('organization_id', $organizationId)))
            ->orderByDesc('last_seen_at')
            ->orderBy('id')
            ->limit($siteLimit)
            ->get();

        if ($sites->isEmpty()) {
            $this->info('No eligible sites found for scheduled scans.');

            return self::SUCCESS;
        }

        foreach ($sites as $site) {
            ScanSiteForRefreshOpportunities::dispatch(
                siteId: (string) $site->id,
                organizationId: $organizationId,
                workspaceId: $workspaceId,
                locale: $locale,
                statuses: $statuses !== [] ? $statuses : ['published'],
                recentDays: $recentDays,
                limit: $contentLimit,
            )->onQueue($queue);

            ScanSiteForLocalizationIssues::dispatch(
                siteId: (string) $site->id,
                organizationId: $organizationId,
                workspaceId: $workspaceId,
                locale: $locale,
                statuses: $statuses !== [] ? $statuses : ['published'],
                recentDays: $recentDays,
                limit: $contentLimit,
            )->onQueue($queue);
        }

        $this->info(sprintf(
            'Dispatched scheduled scans for %d site(s) with up to %d content item(s) per scan.',
            $sites->count(),
            $contentLimit,
        ));

        return self::SUCCESS;
    }

    private function optionAsInt(string $name): ?int
    {
        $value = $this->option($name);

        return is_numeric($value) ? (int) $value : null;
    }

    private function optionAsString(string $name): ?string
    {
        $value = trim((string) ($this->option($name) ?? ''));

        return $value !== '' ? $value : null;
    }
}
