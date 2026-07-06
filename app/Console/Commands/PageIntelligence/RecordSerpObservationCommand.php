<?php

namespace App\Console\Commands\PageIntelligence;

use App\Models\ClientSite;
use App\Models\Workspace;
use App\Services\PageIntelligence\Serp\RecordSerpObservationAction;
use App\Services\PageIntelligence\Serp\SerpObservationResult;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class RecordSerpObservationCommand extends Command
{
    protected $signature = 'page-intelligence:record-serp-observation
        {query : Search query}
        {url : Result URL}
        {--workspace= : Workspace UUID}
        {--site= : Optional client site UUID}
        {--locale= : SERP locale}
        {--country= : SERP country code}
        {--device=desktop : Device class}
        {--engine=google : Search engine}
        {--observed-at= : Observation timestamp}
        {--result-type=organic : Result type}
        {--position= : Organic position}
        {--absolute-position= : Absolute SERP position}
        {--title= : Result title}
        {--snippet= : Result snippet}
        {--search-volume= : Estimated monthly search volume}
        {--intent= : Keyword intent}
        {--click-potential= : Click potential from 0 to 1}
        {--feature=* : SERP feature key}
        {--competitor=* : Competitor domain or descriptor}';

    protected $description = 'Record a manual/imported SERP visibility observation for a monitored page.';

    public function handle(RecordSerpObservationAction $action): int
    {
        try {
            [$workspace, $site] = $this->resolveContext();

            $observation = $action->execute($workspace, new SerpObservationResult(
                query: (string) $this->argument('query'),
                pageUrl: (string) $this->argument('url'),
                locale: $this->stringOption('locale'),
                country: $this->stringOption('country'),
                device: $this->stringOption('device') ?? 'desktop',
                searchEngine: $this->stringOption('engine') ?? 'google',
                observedAt: $this->stringOption('observed-at') ? Carbon::parse((string) $this->option('observed-at')) : null,
                resultType: $this->stringOption('result-type') ?? 'organic',
                position: $this->intOption('position'),
                absolutePosition: $this->intOption('absolute-position') ?? $this->intOption('position'),
                title: $this->stringOption('title'),
                snippet: $this->stringOption('snippet'),
                serpFeatures: $this->option('feature') ?: [],
                competitorPresence: array_map(static fn (string $competitor): array => ['domain' => $competitor], $this->option('competitor') ?: []),
                searchVolume: $this->intOption('search-volume'),
                keywordIntent: $this->stringOption('intent'),
                clickPotential: $this->floatOption('click-potential'),
                rawPayload: [
                    'source' => 'artisan',
                    'features' => $this->option('feature') ?: [],
                    'competitors' => $this->option('competitor') ?: [],
                ],
                providerKey: 'manual',
            ), $site);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('SERP observation recorded.');
        $this->line('Observation ID: '.$observation->id);
        $this->line('Monitored page ID: '.$observation->monitored_page_id);
        $this->line('Query: '.$observation->query);
        $this->line('Position: '.($observation->absolute_position ?? 'unknown'));
        $this->line('Visibility score: '.$observation->visibility_score);

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

    private function stringOption(string $key): ?string
    {
        $value = trim((string) $this->option($key));

        return $value === '' ? null : $value;
    }

    private function intOption(string $key): ?int
    {
        $value = $this->stringOption($key);

        return $value === null ? null : (int) $value;
    }

    private function floatOption(string $key): ?float
    {
        $value = $this->stringOption($key);

        return $value === null ? null : (float) $value;
    }
}
