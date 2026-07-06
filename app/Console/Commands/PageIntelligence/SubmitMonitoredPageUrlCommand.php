<?php

namespace App\Console\Commands\PageIntelligence;

use App\Models\ClientSite;
use App\Models\Workspace;
use App\Services\PageIntelligence\SubmitMonitoredPageAction;
use Illuminate\Console\Command;
use InvalidArgumentException;

class SubmitMonitoredPageUrlCommand extends Command
{
    protected $signature = 'page-intelligence:submit-url
        {url : Public URL to register as a monitored page}
        {--workspace= : Workspace UUID}
        {--site= : Optional client site UUID}
        {--source-type=manual : Source type to store on the monitored page}
        {--page-type= : Optional page type}
        {--canonical-url= : Optional known canonical URL}';

    protected $description = 'Submit one public external URL into the canonical Page Intelligence page layer.';

    public function handle(SubmitMonitoredPageAction $action): int
    {
        try {
            [$workspace, $site] = $this->resolveContext();

            $result = $action->execute(
                workspace: $workspace,
                url: (string) $this->argument('url'),
                site: $site,
                sourceType: trim((string) $this->option('source-type')) ?: 'manual',
                pageType: trim((string) $this->option('page-type')) ?: null,
                canonicalUrl: trim((string) $this->option('canonical-url')) ?: null,
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Monitored page %s.', $result->state()));
        $this->line('ID: '.$result->page->id);
        $this->line('Workspace: '.$result->page->workspace_id);
        if ($result->page->client_site_id) {
            $this->line('Site: '.$result->page->client_site_id);
        }
        $this->line('Canonical URL: '.$result->page->canonical_url);
        $this->line('Canonical URL hash: '.$result->page->canonical_url_hash);
        $this->line('First seen URL: '.$result->page->first_seen_url);
        $this->line('First seen URL hash: '.$result->page->first_seen_url_hash);
        $this->line('Domain: '.$result->page->domain);
        $this->line('State: '.$result->state());

        return self::SUCCESS;
    }

    /**
     * @return array{0:Workspace,1:?ClientSite}
     */
    private function resolveContext(): array
    {
        $workspaceId = trim((string) $this->option('workspace'));
        $siteId = trim((string) $this->option('site'));
        $site = null;

        if ($siteId !== '') {
            $site = ClientSite::query()->with('workspace')->find($siteId);
            if (! $site) {
                throw new InvalidArgumentException('Client site not found.');
            }

            if ($workspaceId === '') {
                $workspaceId = (string) $site->workspace_id;
            } elseif ((string) $site->workspace_id !== $workspaceId) {
                throw new InvalidArgumentException('The selected site does not belong to the selected workspace.');
            }
        }

        if ($workspaceId === '') {
            throw new InvalidArgumentException('Provide --workspace or --site.');
        }

        $workspace = Workspace::query()->find($workspaceId);
        if (! $workspace) {
            throw new InvalidArgumentException('Workspace not found.');
        }

        return [$workspace, $site];
    }
}
