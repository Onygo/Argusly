<?php

namespace App\Services\ContentAutomation;

use App\Enums\ContentAutomationMode;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentAutomation;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ContentAutomationPlanner
{
    public function __construct(
        private readonly AutomationLocaleResolver $localeResolver,
    ) {}

    /**
     * @return array{
     *   chain_title:string,
     *   chain_theme:string,
     *   source_locale:string,
     *   locales:array<int,string>,
     *   articles:array<int,array<string,mixed>>
     * }
     */
    public function plan(ContentAutomation $automation): array
    {
        $automation->loadMissing([
            'workspace.companyProfile',
            'workspace.contents',
            'clientSite',
            'brandVoice',
            'teamPersona',
            'buyerPersona',
        ]);

        $workspace = $automation->workspace;
        $site = $automation->clientSite ?: $this->resolveFallbackSite($workspace);
        $sourceLocale = $this->localeResolver->sourceLocale($automation);
        $configuredLocales = $this->localeResolver->configuredLocales($automation);
        $topic = trim((string) $automation->topic_scope);
        $goal = trim((string) ($automation->content_goal ?? ''));
        $contentPillars = $this->contentPillars($automation);
        $count = $automation->mode === ContentAutomationMode::SINGLE_POST
            ? 1
            : max(1, (int) $automation->chain_size);

        $recentTitles = $this->recentContentFingerprints($automation, $workspace, $site);
        $templates = $this->templatesForMode($automation->mode, $count);
        $plannedTitles = [];
        $articles = [];

        foreach ($templates as $index => $template) {
            $title = $this->ensureUniqueTitle(
                initial: $this->titleForTemplate($topic, $goal, $template, $index + 1),
                usedTitles: array_merge($recentTitles, $plannedTitles),
                template: $template,
            );

            if ($title === null) {
                continue;
            }

            $plannedTitles[] = $title;
            $relatedKeywords = $this->relatedKeywords($topic, $template, $contentPillars);

            $articles[] = [
                'sequence' => $index + 1,
                'title' => $title,
                'angle' => (string) ($template['angle'] ?? ''),
                'search_intent' => (string) ($template['search_intent'] ?? 'informational'),
                'funnel_stage' => (string) ($template['funnel_stage'] ?? ($automation->funnel_stage ?? '')),
                'related_keywords' => $relatedKeywords,
                'internal_link_targets' => array_values(array_filter($plannedTitles, fn (string $candidate): bool => $candidate !== $title)),
                'target_locale' => $sourceLocale,
                'goal' => $goal !== '' ? $goal : (string) ($template['goal'] ?? ''),
                'pillar_role' => (string) ($template['pillar_role'] ?? 'supporting'),
            ];
        }

        return [
            'chain_title' => $this->chainTitle($topic, $goal, $automation->mode),
            'chain_theme' => $contentPillars[0] ?? $topic,
            'source_locale' => $sourceLocale,
            'locales' => $configuredLocales,
            'articles' => $articles,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function recentContentFingerprints(
        ContentAutomation $automation,
        ?Workspace $workspace,
        ?ClientSite $site,
    ): array {
        if (! $workspace) {
            return [];
        }

        return Content::query()
            ->when(
                $site instanceof ClientSite,
                fn ($query) => $query->where('client_site_id', $site->id),
                fn ($query) => $query->where('workspace_id', $workspace->id),
            )
            ->latest('created_at')
            ->limit(50)
            ->get(['title', 'primary_keyword'])
            ->flatMap(function (Content $content): array {
                return array_values(array_filter([
                    $this->fingerprint((string) $content->title),
                    $this->fingerprint((string) ($content->primary_keyword ?? '')),
                ]));
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function templatesForMode(ContentAutomationMode $mode, int $count): array
    {
        $catalog = match ($mode) {
            ContentAutomationMode::SINGLE_POST => [
                ['angle' => 'Practical overview', 'search_intent' => 'informational', 'pillar_role' => 'single'],
            ],
            ContentAutomationMode::PILLAR_PLUS_CLUSTER => [
                ['angle' => 'Complete guide', 'search_intent' => 'informational', 'pillar_role' => 'pillar'],
                ['angle' => 'Implementation steps', 'search_intent' => 'commercial', 'pillar_role' => 'cluster'],
                ['angle' => 'Checklist', 'search_intent' => 'commercial', 'pillar_role' => 'cluster'],
                ['angle' => 'Common mistakes', 'search_intent' => 'informational', 'pillar_role' => 'cluster'],
                ['angle' => 'Template or framework', 'search_intent' => 'commercial', 'pillar_role' => 'cluster'],
                ['angle' => 'Comparison', 'search_intent' => 'commercial', 'pillar_role' => 'cluster'],
            ],
            ContentAutomationMode::CHAIN => [
                ['angle' => 'Strategic overview', 'search_intent' => 'informational', 'pillar_role' => 'supporting'],
                ['angle' => 'Step-by-step implementation', 'search_intent' => 'commercial', 'pillar_role' => 'supporting'],
                ['angle' => 'Checklist', 'search_intent' => 'commercial', 'pillar_role' => 'supporting'],
                ['angle' => 'Common mistakes', 'search_intent' => 'informational', 'pillar_role' => 'supporting'],
                ['angle' => 'Comparison', 'search_intent' => 'commercial', 'pillar_role' => 'supporting'],
                ['angle' => 'Use case', 'search_intent' => 'commercial', 'pillar_role' => 'supporting'],
                ['angle' => 'ROI and business case', 'search_intent' => 'transactional', 'pillar_role' => 'supporting'],
            ],
        };

        return collect(range(0, max(0, $count - 1)))
            ->map(fn (int $index): array => $catalog[$index % count($catalog)])
            ->values()
            ->all();
    }

    private function chainTitle(string $topic, string $goal, ContentAutomationMode $mode): string
    {
        $base = Str::title($topic);

        return match ($mode) {
            ContentAutomationMode::SINGLE_POST => $base,
            ContentAutomationMode::PILLAR_PLUS_CLUSTER => $goal !== ''
                ? $base . ' pillar cluster for ' . Str::lower($goal)
                : $base . ' pillar cluster',
            default => $goal !== ''
                ? $base . ' chain for ' . Str::lower($goal)
                : $base . ' content chain',
        };
    }

    private function titleForTemplate(string $topic, string $goal, array $template, int $sequence): string
    {
        $topicTitle = Str::title($topic);
        $angle = trim((string) ($template['angle'] ?? ''));

        if ((string) ($template['pillar_role'] ?? '') === 'pillar') {
            return $topicTitle . ': complete guide';
        }

        $goalSuffix = $goal !== '' ? ' for ' . Str::lower($goal) : '';

        return match (Str::lower($angle)) {
            'step-by-step implementation' => $topicTitle . ': step-by-step implementation' . $goalSuffix,
            'checklist' => $topicTitle . ' checklist' . $goalSuffix,
            'common mistakes' => $topicTitle . ': common mistakes to avoid',
            'template or framework' => $topicTitle . ': template and framework options',
            'comparison' => $topicTitle . ': compare approaches and tools',
            'use case' => $topicTitle . ': use cases in practice',
            'roi and business case' => $topicTitle . ': roi and business case',
            default => $sequence === 1
                ? $topicTitle . ': strategic overview' . $goalSuffix
                : $topicTitle . ': ' . Str::lower($angle ?: 'editorial angle'),
        };
    }

    /**
     * @param  array<int, string>  $usedTitles
     */
    private function ensureUniqueTitle(string $initial, array $usedTitles, array $template): ?string
    {
        $candidates = collect([
            $initial,
            $initial . ' for teams',
            $initial . ' - ' . Str::lower((string) ($template['search_intent'] ?? 'informational')),
        ]);

        foreach ($candidates as $candidate) {
            $fingerprint = $this->fingerprint((string) $candidate);

            if ($fingerprint === '' || in_array($fingerprint, $usedTitles, true)) {
                continue;
            }

            return (string) $candidate;
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function relatedKeywords(string $topic, array $template, array $contentPillars): array
    {
        $topicSlug = Str::of($topic)->lower()->replace('-', ' ')->value();
        $angle = Str::of((string) ($template['angle'] ?? ''))->lower()->value();

        return collect([
            $topicSlug,
            $topicSlug . ' strategy',
            $topicSlug . ' best practices',
            $topicSlug . ' ' . $angle,
            ...$contentPillars,
        ])
            ->map(fn (string $keyword): string => trim($keyword))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function contentPillars(ContentAutomation $automation): array
    {
        $raw = trim((string) data_get($automation->settings, 'content_pillars', ''));

        if ($raw === '') {
            return [];
        }

        return collect(preg_split('/[\r\n,]+/', $raw) ?: [])
            ->map(fn (string $value): string => trim($value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function fingerprint(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/i', ' ')
            ->squish()
            ->value();
    }

    private function resolveFallbackSite(?Workspace $workspace): ?ClientSite
    {
        if (! $workspace) {
            return null;
        }

        return ClientSite::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->where('status', '!=', 'disabled')
            ->orderBy('created_at')
            ->first();
    }
}
