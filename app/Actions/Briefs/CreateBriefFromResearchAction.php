<?php

namespace App\Actions\Briefs;

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\ResearchProject;
use App\Models\User;
use App\Services\Briefs\BriefGapAnalyzer;
use App\Services\Entitlements\FeatureGate;
use App\Services\Entitlements\WorkspaceEntitlementsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CreateBriefFromResearchAction
{
    public function __construct(
        private readonly FeatureGate $featureGate,
        private readonly WorkspaceEntitlementsService $entitlements,
        private readonly BriefGapAnalyzer $gapAnalyzer,
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function execute(User $user, array $payload): Brief
    {
        $projectId = trim((string) ($payload['research_project_id'] ?? ''));
        if ($projectId === '') {
            throw new RuntimeException('A research project is required.');
        }

        $project = ResearchProject::query()
            ->with(['workspace', 'clientSite', 'findings'])
            ->find($projectId);

        if (! $project || ! $project->workspace) {
            throw new RuntimeException('Research project not found.');
        }

        if ((int) $project->workspace->organization_id !== (int) $user->organization_id) {
            throw new RuntimeException('Research project is not available for your organization.');
        }

        $site = $this->resolveSite($user, $project, $payload['site_id'] ?? null);
        $workspace = $site->workspace;

        if (! $workspace) {
            throw new RuntimeException('Workspace context is missing for selected site.');
        }

        $this->assertFeatureEnabled($workspace);
        $this->entitlements->consumeBriefQuota($workspace);

        $summary = is_array($project->summary) ? $project->summary : [];
        $seed = $this->buildResearchSeed($project, $summary);

        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            $title = (string) ($seed['title_candidates'][0] ?? $project->name);
        }

        $language = trim((string) ($payload['language'] ?? 'en'));
        $contentType = trim((string) ($payload['content_type'] ?? 'blog')) ?: 'blog';

        $brief = DB::transaction(function () use ($project, $site, $user, $title, $language, $contentType, $seed): Brief {
            $brief = Brief::query()->create([
                'client_site_id' => $site->id,
                'created_by_user_id' => $user->id,
                'status' => 'draft',
                'source' => 'client_ui',
                'progress' => 0,
                'title' => $title,
                'language' => $language,
                'content_type' => $contentType,
                'output_type' => $this->mapContentTypeToOutputType($contentType),
                'primary_keyword' => (string) ($seed['primary_keyword'] ?? ''),
                'secondary_keywords' => $seed['semantic_terms'],
                'target_audience' => (string) ($seed['audience'] ?? ''),
                'search_intent' => (string) ($seed['search_intent'] ?? ''),
                'unique_angle' => (string) ($seed['angles'][0] ?? ''),
                'key_points' => $seed['key_points'],
                'call_to_action' => (string) ($seed['cta_direction'] ?? ''),
                'notes' => (string) ($seed['notes'] ?? ''),
                'desired_length_min' => 900,
                'desired_length_max' => 1200,
                'client_refs' => [
                    'client_type' => 'client_ui',
                    'site_url' => (string) ($site->site_url ?? ''),
                    'brief_intelligence' => [
                        'research_project_id' => (string) $project->id,
                        'linked_research' => [
                            'project_id' => (string) $project->id,
                            'project_name' => (string) $project->name,
                            'generated_at' => now()->toIso8601String(),
                        ],
                        'seed_from_research' => $seed,
                        'applied_suggestion_history' => [],
                    ],
                ],
                'wp_site_id' => (string) $site->id,
            ]);

            $project->brief_id = $brief->id;
            $project->save();

            return $brief;
        });

        $analysis = $this->gapAnalyzer->analyze($brief);
        $refs = is_array($brief->client_refs) ? $brief->client_refs : [];
        $intelligence = is_array($refs['brief_intelligence'] ?? null) ? $refs['brief_intelligence'] : [];
        $intelligence['completeness'] = $analysis;
        $refs['brief_intelligence'] = $intelligence;
        $brief->client_refs = $refs;
        $brief->save();

        return $brief->fresh();
    }

    private function resolveSite(User $user, ResearchProject $project, mixed $siteId): ClientSite
    {
        $id = trim((string) $siteId);

        if ($id !== '') {
            $site = ClientSite::query()->with('workspace')->find($id);

            if (! $site || (int) ($site->workspace?->organization_id ?? 0) !== (int) $user->organization_id) {
                throw new RuntimeException('Selected site is not available for your organization.');
            }

            return $site;
        }

        if ($project->clientSite) {
            return $project->clientSite;
        }

        throw new RuntimeException('Select a site to create a brief from this research project.');
    }

    /**
     * @param array<string,mixed> $summary
     * @return array<string,mixed>
     */
    private function buildResearchSeed(ResearchProject $project, array $summary): array
    {
        $highlights = (array) data_get($summary, 'highlights', []);
        $briefEnrichment = (array) data_get($summary, 'brief_enrichment', []);

        $titleCandidates = collect([
            data_get($summary, 'model_summary.executive_summary'),
            $project->name,
        ])
            ->merge((array) data_get($summary, 'model_summary.key_insights', []))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->map(fn (string $title): string => mb_substr($title, 0, 140))
            ->unique()
            ->take(6)
            ->values()
            ->all();

        $keywordCluster = collect((array) data_get($briefEnrichment, 'keyword_clusters', []))
            ->merge((array) ($project->target_keywords ?? []))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->take(20)
            ->values()
            ->all();

        $semanticTerms = collect((array) data_get($highlights, 'entities', []))
            ->merge($keywordCluster)
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->take(20)
            ->values()
            ->all();

        $keyPoints = collect((array) data_get($highlights, 'insights', []))
            ->merge((array) data_get($highlights, 'statistics', []))
            ->merge((array) data_get($highlights, 'questions', []))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->take(12)
            ->values()
            ->all();

        $headings = collect((array) data_get($summary, 'model_summary.key_insights', []))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->take(10)
            ->values()
            ->all();

        $angles = collect((array) data_get($briefEnrichment, 'recommended_angles', []))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->take(10)
            ->values()
            ->all();

        $questions = collect((array) data_get($highlights, 'questions', []))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->take(10)
            ->values()
            ->all();

        $audience = $semanticTerms[0] ?? '';
        $searchIntent = $questions[0] ?? '';
        $cta = $angles[0] ?? '';

        $notes = trim(implode("\n\n", array_filter([
            trim((string) ($project->human_summary ?? '')),
            $questions !== [] ? "Target questions:\n- " . implode("\n- ", $questions) : null,
            $headings !== [] ? "Suggested headings:\n- " . implode("\n- ", $headings) : null,
        ])));

        return [
            'title_candidates' => $titleCandidates,
            'primary_keyword' => (string) ($keywordCluster[0] ?? ''),
            'keyword_cluster' => $keywordCluster,
            'semantic_terms' => $semanticTerms,
            'target_questions' => $questions,
            'recommended_headings' => $headings,
            'angles' => $angles,
            'audience' => $audience,
            'search_intent' => $searchIntent,
            'cta_direction' => $cta,
            'key_points' => $keyPoints,
            'notes' => $notes,
        ];
    }

    private function mapContentTypeToOutputType(string $contentType): string
    {
        return match ($contentType) {
            'landing' => 'seo_page',
            'linkedin' => 'linkedin_post',
            'email' => 'email',
            default => 'kb_article',
        };
    }

    private function assertFeatureEnabled($workspace): void
    {
        if (! $this->toBool($this->featureGate->value($workspace, 'brief_intelligence_enabled', false), false)) {
            throw new AuthorizationException('Brief intelligence is not enabled for this workspace.');
        }
    }

    private function toBool(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        return ! in_array(strtolower(trim((string) $value)), ['', '0', 'false', 'off', 'no'], true);
    }
}
