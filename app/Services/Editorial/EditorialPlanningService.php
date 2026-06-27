<?php

namespace App\Services\Editorial;

use App\Models\Brief;
use App\Models\Content;
use App\Models\Draft;
use App\Models\ResearchFinding;
use App\Models\ResearchProject;
use App\Models\Workspace;
use App\Services\HumanContent\CorpusDiversityService;
use Illuminate\Support\Str;

class EditorialPlanningService
{
    public const VERSION = 'editorial_plan_v1';

    public function __construct(
        private readonly EditorialPatternLibrary $patternLibrary,
        private readonly CorpusDiversityService $corpusDiversity,
    ) {}

    /**
     * @param array<string,mixed> $draftMeta
     * @return array<string,mixed>
     */
    public function createForBrief(Brief $brief, array $draftMeta = []): array
    {
        if ($brief->exists) {
            $brief->loadMissing([
                'clientSite.workspace.companyProfile',
                'clientSite.workspace.organization.organizationProfile',
                'clientSite.workspace.defaultBrandVoice',
                'content.writerProfile',
                'content.brandVoice',
                'researchProjects.findings',
            ]);
        }

        $workspace = $brief->clientSite?->workspace;
        $researchProject = $this->resolveResearchProject($brief);
        $researchSummary = is_array($researchProject?->summary) ? $researchProject->summary : [];
        $researchInsights = $this->researchInsights($researchProject, $researchSummary);
        $topic = $this->topic($brief, $draftMeta);
        $audience = $this->audience($brief, $draftMeta, $workspace);
        $searchIntent = trim((string) ($brief->search_intent ?: ($draftMeta['search_intent'] ?? '')));
        $funnelStage = trim((string) ($brief->funnel_stage ?: ($draftMeta['funnel_stage'] ?? '')));
        $uniqueAngle = $this->uniqueAngle($brief, $researchInsights, $draftMeta);
        $previousArticles = $this->previousRelatedArticles($brief, $workspace, $topic);
        $corpusDiversity = $this->corpusDiversity->planningGuidance($brief, $draftMeta, $previousArticles);
        $brandVoice = $this->brandVoiceContext($brief);
        $writerProfile = $this->writerProfileContext($brief);
        $company = $this->companyContext($workspace);
        $keyPoints = $this->listValue($brief->key_points ?: ($draftMeta['key_points'] ?? []));
        $patternSelection = $this->patternLibrary->select([
            'title' => (string) $brief->title,
            'topic' => $topic,
            'primary_keyword' => (string) ($brief->primary_keyword ?: ($draftMeta['primary_keyword'] ?? '')),
            'secondary_keywords' => (array) ($brief->secondary_keywords ?: ($draftMeta['secondary_keywords'] ?? [])),
            'unique_angle' => $uniqueAngle,
            'notes' => (string) $brief->notes,
            'audience' => $audience,
            'search_intent' => $searchIntent,
            'funnel_stage' => $funnelStage,
            'research_insights' => $researchInsights,
            'previous_related_articles' => $previousArticles,
            'key_points' => $keyPoints,
        ]);
        $primaryPattern = $patternSelection['primary'];
        $secondaryPattern = $patternSelection['secondary'];

        return [
            'version' => self::VERSION,
            'generated_at' => now()->toIso8601String(),
            'source' => [
                'brief_id' => (string) ($brief->id ?? ''),
                'content_id' => (string) ($brief->content_id ?? ''),
                'research_project_id' => (string) ($researchProject?->id ?? ''),
            ],
            'central_thesis' => $this->centralThesis($topic, $uniqueAngle, $company, $searchIntent),
            'reader_misconception' => $this->readerMisconception($topic, $searchIntent),
            'unique_angle' => $uniqueAngle,
            'editorial_goal' => $this->editorialGoal($topic, $audience, $searchIntent, $funnelStage),
            'business_perspective' => $this->businessPerspective($company, $funnelStage),
            'evidence_plan' => $this->evidencePlan($researchInsights, $keyPoints, $previousArticles),
            'counterarguments' => $this->counterarguments($topic, $researchInsights, $funnelStage),
            'expert_observations' => $this->expertObservations($researchInsights, $company, $writerProfile),
            'primary_pattern' => $primaryPattern,
            'secondary_pattern' => $secondaryPattern,
            'storytelling_pattern' => $this->storytellingPattern($searchIntent, $funnelStage, $primaryPattern, $secondaryPattern),
            'narrative_style' => $this->narrativeStyle($brandVoice, $writerProfile),
            'rhythm_plan' => $this->rhythmPlan($topic, $primaryPattern, $secondaryPattern),
            'section_intentions' => $this->sectionIntentions($topic, $uniqueAngle, $searchIntent, $funnelStage, $researchInsights, $previousArticles),
            'corpus_diversity_guidance' => $corpusDiversity,
            'example_opportunities' => $this->exampleOpportunities($researchInsights, $previousArticles, $topic),
            'analogy_opportunities' => $this->analogyOpportunities($topic, $audience),
            'business_recommendations' => $this->businessRecommendations($topic, $company, $funnelStage),
            'things_to_avoid' => $this->thingsToAvoid($brief, $brandVoice, $previousArticles, $corpusDiversity),
            'curiosity_gap' => $this->curiosityGap($topic, $uniqueAngle, $researchInsights),
            'expected_reader_takeaway' => $this->readerTakeaway($topic, $audience, $funnelStage),
            'context_snapshot' => [
                'topic' => $topic,
                'audience' => $audience,
                'search_intent' => $searchIntent,
                'funnel_stage' => $funnelStage,
                'brand_voice' => $brandVoice,
                'writer_profile' => $writerProfile,
                'company' => $company,
                'research_summary' => $this->compactResearchSummary($researchSummary),
                'research_insights' => $researchInsights,
                'previous_related_articles' => $previousArticles,
                'corpus_diversity' => $corpusDiversity,
                'pattern_selection_scores' => $patternSelection['scores'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function createForDraft(Draft $draft): array
    {
        $draft->loadMissing([
            'brief.clientSite.workspace.companyProfile',
            'brief.clientSite.workspace.organization.organizationProfile',
            'brief.clientSite.workspace.defaultBrandVoice',
            'brief.content.writerProfile',
            'brief.content.brandVoice',
            'brief.researchProjects.findings',
        ]);

        $brief = $draft->brief;
        if ($brief instanceof Brief) {
            return $this->createForBrief($brief, is_array($draft->meta) ? $draft->meta : []);
        }

        return $this->createFallbackForDraft($draft);
    }

    /**
     * @param array<string,mixed> $plan
     */
    public function toPromptSection(array $plan): string
    {
        if ($plan === []) {
            return '';
        }

        $lines = [
            'EDITORIAL PLAN',
            'Use this plan as the governing editorial brief. Do not output the plan itself.',
            'Do not treat section intentions as fixed headings; turn them into natural, specific sections.',
            'Do not generate generic opening/main/practical/conclusion structure.',
            '',
        ];

        foreach ($this->promptFields() as $key => $label) {
            $value = data_get($plan, $key);
            if ($this->isBlank($value)) {
                continue;
            }

            $lines[] = $label . ':';
            foreach ($this->formatPromptValue($value) as $row) {
                $lines[] = $row;
            }
            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @return array<string,string>
     */
    private function promptFields(): array
    {
        return [
            'central_thesis' => 'Central thesis',
            'reader_misconception' => 'Reader misconception to correct',
            'unique_angle' => 'Unique angle',
            'editorial_goal' => 'Editorial goal',
            'business_perspective' => 'Business perspective',
            'evidence_plan' => 'Evidence plan',
            'counterarguments' => 'Counterarguments to handle',
            'expert_observations' => 'Expert observations',
            'primary_pattern' => 'Primary editorial pattern',
            'secondary_pattern' => 'Secondary editorial pattern',
            'storytelling_pattern' => 'Storytelling pattern',
            'narrative_style' => 'Narrative style',
            'rhythm_plan' => 'Rhythm plan',
            'section_intentions' => 'Section intentions',
            'corpus_diversity_guidance' => 'Corpus diversity guidance',
            'example_opportunities' => 'Example opportunities',
            'analogy_opportunities' => 'Analogy opportunities',
            'business_recommendations' => 'Business recommendations',
            'things_to_avoid' => 'Things to avoid',
            'curiosity_gap' => 'Curiosity gap',
            'expected_reader_takeaway' => 'Expected reader takeaway',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function formatPromptValue(mixed $value): array
    {
        if (is_array($value)) {
            $isList = array_is_list($value);

            return collect($value)
                ->map(function (mixed $row, int|string $key) use ($isList): ?string {
                    if (is_array($row)) {
                        $encoded = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                        return $encoded ? '- ' . $encoded : null;
                    }

                    $text = trim((string) $row);
                    if ($text === '') {
                        return null;
                    }

                    return $isList ? '- ' . $text : '- ' . $key . ': ' . $text;
                })
                ->filter()
                ->values()
                ->all();
        }

        $text = trim((string) $value);

        return $text === '' ? [] : ['- ' . $text];
    }

    private function resolveResearchProject(Brief $brief): ?ResearchProject
    {
        $linkedId = trim((string) data_get($brief->client_refs, 'brief_intelligence.research_project_id', ''));
        $projects = $brief->relationLoaded('researchProjects') ? $brief->researchProjects : collect();

        if ($linkedId !== '') {
            $linked = $projects->firstWhere('id', $linkedId);
            if ($linked instanceof ResearchProject) {
                return $linked;
            }

            return ResearchProject::query()->with('findings')->find($linkedId);
        }

        return $projects
            ->sortByDesc(fn (ResearchProject $project): int => $project->updated_at?->getTimestamp() ?? 0)
            ->first();
    }

    /**
     * @param array<string,mixed> $summary
     * @return array<int,string>
     */
    private function researchInsights(?ResearchProject $project, array $summary): array
    {
        $fromFindings = $project instanceof ResearchProject
            ? $project->findings
                ->sortByDesc(fn (ResearchFinding $finding): float => (float) $finding->confidence_score)
                ->map(fn (ResearchFinding $finding): string => trim((string) $finding->finding_text))
                ->filter()
                ->take(8)
                ->values()
                ->all()
            : [];

        return collect($fromFindings)
            ->merge((array) data_get($summary, 'highlights.insights', []))
            ->merge((array) data_get($summary, 'highlights.statistics', []))
            ->merge((array) data_get($summary, 'model_summary.key_insights', []))
            ->map(fn (mixed $item): string => Str::limit(trim((string) $item), 260, ''))
            ->filter()
            ->unique()
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $draftMeta
     */
    private function topic(Brief $brief, array $draftMeta): string
    {
        return trim((string) (
            $brief->title
            ?: $brief->primary_keyword
            ?: ($draftMeta['primary_keyword'] ?? '')
            ?: 'the topic'
        ));
    }

    /**
     * @param array<string,mixed> $draftMeta
     */
    private function audience(Brief $brief, array $draftMeta, ?Workspace $workspace): string
    {
        return trim((string) (
            $brief->target_audience
            ?: $brief->audience
            ?: ($draftMeta['audience'] ?? '')
            ?: $workspace?->companyProfile?->target_audience
            ?: 'business decision makers'
        ));
    }

    /**
     * @param array<int,string> $researchInsights
     * @param array<string,mixed> $draftMeta
     */
    private function uniqueAngle(Brief $brief, array $researchInsights, array $draftMeta): string
    {
        $explicit = trim((string) ($brief->unique_angle ?: ($draftMeta['unique_angle'] ?? '')));
        if ($explicit !== '') {
            return $explicit;
        }

        $firstInsight = $researchInsights[0] ?? '';

        return $firstInsight !== ''
            ? 'Use the strongest research observation as the angle: ' . Str::limit($firstInsight, 180, '')
            : 'Turn the topic into a practical decision framework rather than a generic overview.';
    }

    /**
     * @return array<string,mixed>
     */
    private function brandVoiceContext(Brief $brief): array
    {
        $voice = $brief->content?->brandVoice ?: $brief->clientSite?->workspace?->defaultBrandVoice;

        return [
            'name' => (string) ($voice?->name ?? ''),
            'tone' => (string) ($voice?->tone_of_voice ?? $voice?->default_tone ?? $brief->tone_of_voice ?? ''),
            'style' => (string) ($voice?->writing_style ?? $voice?->style_guide ?? ''),
            'do' => $this->lineList((string) ($voice?->do_rules ?? '')),
            'do_not' => $this->lineList((string) ($voice?->dont_rules ?? '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function writerProfileContext(Brief $brief): array
    {
        $profile = $brief->content?->writerProfile;

        return [
            'name' => (string) ($profile?->name ?? ''),
            'summary' => (string) ($profile?->tone_summary ?? $profile?->writing_style_summary ?? ''),
            'tone_traits' => array_values(array_filter([
                (string) ($profile?->tone_summary ?? ''),
                (string) ($profile?->structure_summary ?? ''),
            ])),
            'point_of_view' => (string) ($profile?->writing_style_summary ?? ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function companyContext(?Workspace $workspace): array
    {
        $organization = $workspace?->organization;
        $organizationProfile = $organization?->organizationProfile;
        $companyProfile = $workspace?->companyProfile;

        return [
            'name' => (string) ($companyProfile?->company_name ?: $organization?->name ?: $workspace?->name ?: ''),
            'industry' => (string) ($companyProfile?->industry ?: $organization?->industry ?: ''),
            'positioning' => (string) ($organizationProfile?->brand_summary ?: $organization?->positioning_statement ?: ''),
            'proof_points' => $this->lineList((string) ($companyProfile?->proof_points ?? '')),
            'services' => $this->lineList((string) ($companyProfile?->key_services ?? '')),
            'differentiators' => (array) ($organizationProfile?->differentiators ?? []),
        ];
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function previousRelatedArticles(Brief $brief, ?Workspace $workspace, string $topic): array
    {
        if (! $workspace?->getKey()) {
            return [];
        }

        $needle = Str::of($topic)->lower()->replaceMatches('/[^a-z0-9\s]+/i', ' ')->squish()->value();
        $terms = collect(explode(' ', $needle))->filter(fn (string $term): bool => mb_strlen($term) >= 4)->take(5)->values();

        return Content::query()
            ->where('workspace_id', (string) $workspace->id)
            ->when($brief->content_id, fn ($query) => $query->where('id', '!=', (string) $brief->content_id))
            ->latest('updated_at')
            ->limit(50)
            ->get(['id', 'title', 'primary_keyword', 'status'])
            ->filter(function (Content $content) use ($terms): bool {
                $haystack = Str::lower(trim((string) $content->title . ' ' . (string) $content->primary_keyword));

                return $terms->contains(fn (string $term): bool => str_contains($haystack, $term));
            })
            ->take(5)
            ->map(fn (Content $content): array => [
                'id' => (string) $content->id,
                'title' => (string) $content->title,
                'primary_keyword' => (string) ($content->primary_keyword ?? ''),
                'status' => (string) ($content->status ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $company
     */
    private function centralThesis(string $topic, string $uniqueAngle, array $company, string $searchIntent): string
    {
        $lens = $searchIntent !== '' ? " for {$searchIntent} intent" : '';
        $positioning = trim((string) ($company['positioning'] ?? ''));

        return Str::limit(sprintf(
            '%s should be explained through %s%s, with the business tradeoffs made explicit%s.',
            $topic,
            Str::lower($uniqueAngle),
            $lens,
            $positioning !== '' ? ' from the company perspective' : ''
        ), 420, '');
    }

    private function readerMisconception(string $topic, string $searchIntent): string
    {
        return $searchIntent === 'commercial' || $searchIntent === 'transactional'
            ? "Readers may assume {$topic} is mainly a vendor-selection question; correct that by showing the operational decision behind the purchase."
            : "Readers may expect a broad definition of {$topic}; correct that by showing the decisions, risks, and proof points that make the topic useful.";
    }

    private function editorialGoal(string $topic, string $audience, string $searchIntent, string $funnelStage): string
    {
        $goal = "Help {$audience} understand what to do about {$topic}, not just what it means.";

        if ($funnelStage !== '') {
            $goal .= " Match the guidance to {$funnelStage} funnel expectations.";
        }

        if ($searchIntent !== '') {
            $goal .= " Satisfy {$searchIntent} search intent with direct answers and useful judgment.";
        }

        return $goal;
    }

    /**
     * @param array<string,mixed> $company
     */
    private function businessPerspective(array $company, string $funnelStage): string
    {
        $industry = trim((string) ($company['industry'] ?? ''));
        $services = (array) ($company['services'] ?? []);
        $service = trim((string) ($services[0] ?? ''));

        return trim(sprintf(
            'Frame the topic as a practical business decision%s%s%s.',
            $industry !== '' ? " in {$industry}" : '',
            $service !== '' ? " connected to {$service}" : '',
            $funnelStage !== '' ? " for {$funnelStage} readers" : ''
        ));
    }

    /**
     * @param array<int,string> $researchInsights
     * @param array<int,string> $keyPoints
     * @param array<int,array<string,string>> $previousArticles
     * @return array<int,string>
     */
    private function evidencePlan(array $researchInsights, array $keyPoints, array $previousArticles): array
    {
        return collect([
            $researchInsights !== [] ? 'Use selected research findings as the first evidence source; cite the observation as an observed pattern, not as article copy.' : null,
            $keyPoints !== [] ? 'Turn brief key points into proof obligations: each major recommendation needs a concrete reason or example.' : null,
            $previousArticles !== [] ? 'Use related existing articles to avoid repetition and create continuity with prior coverage.' : null,
            'Prefer numbers, named patterns, observed behavior, examples, or constraints over generic claims.',
        ])->filter()->values()->all();
    }

    /**
     * @param array<int,string> $researchInsights
     * @return array<int,string>
     */
    private function counterarguments(string $topic, array $researchInsights, string $funnelStage): array
    {
        return collect([
            "Some readers may see {$topic} as too abstract or generic; answer with practical criteria.",
            $funnelStage === 'decision' ? 'Decision-stage readers may worry about implementation risk; address operational constraints directly.' : null,
            $researchInsights !== [] ? 'If research suggests a simple conclusion, show the caveat or boundary condition behind it.' : null,
        ])->filter()->values()->all();
    }

    /**
     * @param array<int,string> $researchInsights
     * @param array<string,mixed> $company
     * @param array<string,mixed> $writerProfile
     * @return array<int,string>
     */
    private function expertObservations(array $researchInsights, array $company, array $writerProfile): array
    {
        $observations = collect($researchInsights)->take(4)->values();
        $pointOfView = trim((string) ($writerProfile['point_of_view'] ?? ''));
        if ($pointOfView !== '') {
            $observations->push('Use the writer perspective: ' . $pointOfView);
        }

        $proofPoints = (array) ($company['proof_points'] ?? []);
        if ($proofPoints !== []) {
            $observations->push('Connect claims to company proof points where relevant: ' . implode('; ', array_slice($proofPoints, 0, 3)));
        }

        return $observations->filter()->unique()->take(6)->values()->all();
    }

    /**
     * @param array<string,mixed>|null $secondaryPattern
     * @param array<string,mixed> $primaryPattern
     */
    private function storytellingPattern(string $searchIntent, string $funnelStage, array $primaryPattern, ?array $secondaryPattern): string
    {
        $movement = trim((string) ($primaryPattern['article_movement'] ?? ''));
        if ($movement !== '') {
            $secondary = trim((string) ($secondaryPattern['name'] ?? ''));

            return $movement . ($secondary !== '' ? " Borrow secondary texture from {$secondary} where it supports the evidence." : '');
        }

        if (in_array($searchIntent, ['commercial', 'transactional'], true) || $funnelStage === 'decision') {
            return 'Problem, decision criteria, tradeoffs, recommendation, next step.';
        }

        if ($searchIntent === 'informational') {
            return 'Direct answer, misconception, practical implications, examples, recommendations.';
        }

        return 'Observation, why it matters, how to evaluate it, what to do next.';
    }

    /**
     * @param array<string,mixed> $brandVoice
     * @param array<string,mixed> $writerProfile
     */
    private function narrativeStyle(array $brandVoice, array $writerProfile): string
    {
        $tone = trim((string) ($brandVoice['tone'] ?? ''));
        $traits = implode(', ', array_slice((array) ($writerProfile['tone_traits'] ?? []), 0, 4));

        return trim(sprintf(
            '%s%s Keep the writing consultative, specific, and measured.',
            $tone !== '' ? "Use {$tone} tone. " : '',
            $traits !== '' ? "Reflect writer traits: {$traits}. " : ''
        ));
    }

    /**
     * @param array<string,mixed>|null $secondaryPattern
     * @param array<string,mixed> $primaryPattern
     */
    private function rhythmPlan(string $topic, array $primaryPattern, ?array $secondaryPattern): string
    {
        $guidance = trim((string) ($primaryPattern['rhythm_guidance'] ?? ''));
        $secondaryGuidance = trim((string) ($secondaryPattern['rhythm_guidance'] ?? ''));
        $base = "Alternate concise explanatory passages about {$topic} with concrete examples, short lists, and explicit recommendation moments.";

        return trim(implode(' ', array_filter([
            $guidance,
            $secondaryGuidance !== '' ? 'Secondary rhythm cue: ' . $secondaryGuidance : null,
            $base,
        ])));
    }

    /**
     * @param array<int,string> $researchInsights
     * @param array<int,array<string,string>> $previousArticles
     * @return array<int,array<string,string>>
     */
    private function sectionIntentions(
        string $topic,
        string $uniqueAngle,
        string $searchIntent,
        string $funnelStage,
        array $researchInsights,
        array $previousArticles,
    ): array {
        $sections = [
            [
                'intention' => 'Answer the reader question directly',
                'job' => "Define the practical meaning of {$topic} and name why the angle matters.",
            ],
            [
                'intention' => 'Correct the common misconception',
                'job' => "Show what readers usually miss when they approach {$topic} generically.",
            ],
            [
                'intention' => 'Build the argument from evidence',
                'job' => $researchInsights !== []
                    ? 'Use research observations to support the thesis without copying source wording.'
                    : 'Use brief facts, company context, and practical examples as evidence.',
            ],
            [
                'intention' => 'Translate the insight into decisions',
                'job' => 'Give ' . ($funnelStage !== '' ? $funnelStage : 'the reader') . ' criteria, tradeoffs, and a recommended path.',
            ],
            [
                'intention' => 'Close with a useful takeaway',
                'job' => 'Summarize the decision the reader can make after reading and connect naturally to the CTA.',
            ],
        ];

        if ($searchIntent === 'commercial' || $previousArticles !== []) {
            array_splice($sections, 3, 0, [[
                'intention' => 'Differentiate from adjacent content and alternatives',
                'job' => $previousArticles !== []
                    ? 'Clarify what this article adds beyond existing related articles.'
                    : 'Show how to compare approaches without turning the piece into a vendor pitch.',
            ]]);
        }

        $sections[0]['editorial_angle'] = $uniqueAngle;

        return $sections;
    }

    /**
     * @param array<int,string> $researchInsights
     * @param array<int,array<string,string>> $previousArticles
     * @return array<int,string>
     */
    private function exampleOpportunities(array $researchInsights, array $previousArticles, string $topic): array
    {
        return collect([
            $researchInsights !== [] ? 'Use one research-backed pattern as a compact example.' : null,
            $previousArticles !== [] ? 'Reference an adjacent existing article theme without repeating it.' : null,
            "Show how a team would apply {$topic} in a real planning or review moment.",
        ])->filter()->values()->all();
    }

    /**
     * @return array<int,string>
     */
    private function analogyOpportunities(string $topic, string $audience): array
    {
        return [
            "Compare {$topic} to a decision checklist for {$audience}: useful only when it changes what the team does next.",
        ];
    }

    /**
     * @param array<string,mixed> $company
     * @return array<int,string>
     */
    private function businessRecommendations(string $topic, array $company, string $funnelStage): array
    {
        $recommendations = [
            "Turn {$topic} into a practical evaluation or action sequence.",
            'Name the decision owner, input needed, and next operational step where possible.',
        ];

        $services = (array) ($company['services'] ?? []);
        if ($services !== []) {
            $recommendations[] = 'Connect recommendations to the relevant service context: ' . implode(', ', array_slice($services, 0, 2)) . '.';
        }

        if ($funnelStage === 'decision') {
            $recommendations[] = 'Include implementation constraints, risk reduction, and selection criteria.';
        }

        return $recommendations;
    }

    /**
     * @param array<string,mixed> $brandVoice
     * @param array<int,array<string,string>> $previousArticles
     * @param array<string,mixed> $corpusDiversity
     * @return array<int,string>
     */
    private function thingsToAvoid(Brief $brief, array $brandVoice, array $previousArticles, array $corpusDiversity = []): array
    {
        return collect([
            'Do not write article copy inside the plan or expose planning language in the final article.',
            'Avoid generic sections such as Opening, Main section, Practical examples, or Conclusion.',
            'Avoid broad claims without an example, metric, observed pattern, or practical constraint.',
            $previousArticles !== [] ? 'Avoid repeating angles already covered by related existing articles.' : null,
            ...((array) data_get($corpusDiversity, 'avoid_repeating', [])),
            ...((array) data_get($corpusDiversity, 'recommendations', [])),
            ...((array) ($brandVoice['do_not'] ?? [])),
            trim((string) $brief->notes) !== '' ? 'Do not copy brief notes verbatim into the article.' : null,
        ])->filter()->unique()->take(14)->values()->all();
    }

    /**
     * @param array<int,string> $researchInsights
     */
    private function curiosityGap(string $topic, string $uniqueAngle, array $researchInsights): string
    {
        return $researchInsights !== []
            ? "The interesting question is not whether {$topic} matters, but what the observed evidence changes about the reader's next decision."
            : "The interesting question is not what {$topic} is, but which practical mistake the reader can avoid by understanding {$uniqueAngle}.";
    }

    private function readerTakeaway(string $topic, string $audience, string $funnelStage): string
    {
        return "{$audience} should leave with a clearer way to evaluate {$topic}" . ($funnelStage !== '' ? " at the {$funnelStage} stage." : '.');
    }

    /**
     * @return array<string,mixed>
     */
    private function createFallbackForDraft(Draft $draft): array
    {
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $brief = new Brief([
            'title' => (string) ($draft->title ?: ($meta['primary_keyword'] ?? 'Untitled content')),
            'language' => (string) ($meta['language'] ?? $draft->getRawOriginal('language') ?? 'en'),
            'primary_keyword' => (string) ($meta['primary_keyword'] ?? ''),
            'secondary_keywords' => (array) ($meta['secondary_keywords'] ?? []),
            'target_audience' => (string) ($meta['audience'] ?? ''),
            'funnel_stage' => (string) ($meta['funnel_stage'] ?? ''),
            'search_intent' => (string) ($meta['search_intent'] ?? ''),
            'tone_of_voice' => (string) ($meta['tone'] ?? ''),
            'unique_angle' => (string) ($meta['unique_angle'] ?? ''),
            'key_points' => (array) ($meta['key_points'] ?? []),
            'call_to_action' => (string) ($meta['call_to_action'] ?? ''),
            'notes' => (string) ($meta['notes'] ?? ''),
        ]);

        return $this->createForBrief($brief, $meta);
    }

    /**
     * @param array<string,mixed> $summary
     * @return array<string,mixed>
     */
    private function compactResearchSummary(array $summary): array
    {
        return [
            'selected_finding_count' => data_get($summary, 'selected_finding_count'),
            'highlights' => [
                'insights' => array_slice((array) data_get($summary, 'highlights.insights', []), 0, 5),
                'statistics' => array_slice((array) data_get($summary, 'highlights.statistics', []), 0, 5),
                'questions' => array_slice((array) data_get($summary, 'highlights.questions', []), 0, 5),
            ],
            'brief_enrichment' => data_get($summary, 'brief_enrichment', []),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function lineList(string $value): array
    {
        return collect(preg_split('/[\r\n,;]+/', $value) ?: [])
            ->map(fn (string $row): string => trim($row))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private function listValue(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            }
        }

        return collect(is_array($value) ? $value : (preg_split('/[\n,]+/', (string) $value) ?: []))
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function isBlank(mixed $value): bool
    {
        if (is_array($value)) {
            return $value === [];
        }

        return trim((string) $value) === '';
    }
}
