<?php

namespace App\Jobs\Agents;

use App\Agents\Localization\LocalizationAgent;
use App\Agents\ScheduledAgentRunner;
use App\Models\ClientSite;
use App\Services\Agents\AgentAutomationSettingsResolver;
use App\Services\Agents\SiteContentScanScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ScanSiteForLocalizationIssues implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 1800;

    /**
     * @param  array<int, string>  $statuses
     */
    public function __construct(
        public string $siteId,
        public ?int $organizationId = null,
        public ?string $workspaceId = null,
        public ?string $locale = null,
        public array $statuses = ['published'],
        public ?int $recentDays = null,
        public int $limit = 25,
    ) {
        $this->queue = 'default';
    }

    public function uniqueId(): string
    {
        return implode(':', [
            'scheduled_localization_scan',
            $this->siteId,
            (string) ($this->organizationId ?? ''),
            (string) ($this->workspaceId ?? ''),
            (string) ($this->locale ?? ''),
            implode(',', $this->statuses),
            (string) ($this->recentDays ?? ''),
            (string) $this->limit,
        ]);
    }

    public function handle(
        SiteContentScanScope $scope,
        ScheduledAgentRunner $runner,
        LocalizationAgent $agent,
        AgentAutomationSettingsResolver $settingsResolver,
    ): void {
        $site = ClientSite::query()
            ->with('workspace')
            ->find($this->siteId);

        if (! $site) {
            return;
        }

        if (! $settingsResolver->localizationChecksEnabledForSite($site)) {
            return;
        }

        $contents = $scope->query(
            site: $site,
            statuses: $this->statuses,
            locale: $this->locale,
            organizationId: $this->organizationId,
            workspaceId: $this->workspaceId,
            recentDays: $this->recentDays,
        )
            ->limit(max(1, $this->limit))
            ->get();

        foreach ($contents as $content) {
            $runner->runForContent(
                agent: $agent,
                content: $content,
                triggerSource: 'schedule.site_localization_scan',
                metadata: [
                    'scan_type' => 'localization',
                    'site_id' => (string) $site->id,
                    'workspace_id' => (string) ($site->workspace_id ?? ''),
                    'locale_filter' => $this->locale,
                    'status_filter' => $this->statuses,
                    'recent_days' => $this->recentDays,
                ],
            );
        }
    }
}
