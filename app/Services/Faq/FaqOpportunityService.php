<?php

namespace App\Services\Faq;

use App\Data\Faq\FaqPageInput;
use App\Enums\FaqFunnelStage;
use App\Enums\FaqSearchIntent;
use App\Enums\FaqStatus;
use App\Enums\FaqType;
use App\Enums\FaqWorkflowStatus;
use App\Models\FaqOpportunityAudit;
use App\Models\FaqQuestion;
use App\Models\Workspace;
use App\Repositories\FaqQuestionRepository;
use App\Services\HumanSignals\HumanSignalContextBuilder;
use Illuminate\Support\Collection;

class FaqOpportunityService
{
    public function __construct(
        private readonly FaqQuestionRepository $faqs,
        private readonly FaqSchemaService $schema,
        private readonly HumanSignalContextBuilder $humanSignalContext,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function analyze(FaqPageInput $input, ?int $userId = null, bool $persist = false): array
    {
        $existingPageFaqs = $this->faqs->publishedForPage($input->pageType, $input->pageSlug, $input->locale);
        $detected = $this->detectMissingQuestions($input, $existingPageFaqs);
        $generated = $this->generateFaqs($input, $detected, $existingPageFaqs);
        $scores = $this->scores($input, $existingPageFaqs, $detected, $generated);
        $schema = $this->schema->forQuestions($this->generatedToModels($generated, $input));
        $schemaErrors = $this->schema->validate($schema);

        $result = [
            'page' => [
                'page_type' => $input->pageType,
                'page_slug' => $input->pageSlug,
                'locale' => $input->locale,
                'title' => $input->pageTitle,
                'sector' => $input->sector,
                'solution_type' => $input->solutionType,
            ],
            'scores' => $scores,
            'detected_gaps' => $detected,
            'recommended_faqs' => $generated,
            'faq_schema' => $schema,
            'schema_validation_errors' => $schemaErrors,
            'internal_link_opportunities' => $this->internalLinkOpportunities($input, $generated),
            'suggested_ctas' => $this->suggestedCtas($input),
        ];

        if ($persist) {
            FaqOpportunityAudit::query()->create([
                'page_type' => $input->pageType,
                'page_slug' => $input->pageSlug,
                'locale' => $input->locale,
                'page_title' => $input->pageTitle,
                'sector' => $input->sector,
                'solution_type' => $input->solutionType,
                'status' => FaqWorkflowStatus::REVIEW_REQUIRED->value,
                'faq_coverage_score' => $scores['faq_coverage_score']['score'],
                'faq_opportunity_score' => $scores['faq_opportunity_score']['score'],
                'ai_visibility_impact_score' => $scores['ai_visibility_impact_score']['score'],
                'seo_impact_score' => $scores['seo_impact_score']['score'],
                'conversion_impact_score' => $scores['conversion_impact_score']['score'],
                'score_rationale' => $scores,
                'missing_questions' => $detected,
                'generated_faqs' => $generated,
                'suggested_internal_links' => $result['internal_link_opportunities'],
                'suggested_ctas' => $result['suggested_ctas'],
                'completed_at' => now(),
                'created_by' => $userId,
            ]);
        }

        return $result;
    }

    /**
     * @param  array<int,array<string,mixed>>  $generatedFaqs
     * @return Collection<int,FaqQuestion>
     */
    public function publishGeneratedFaqs(FaqPageInput $input, array $generatedFaqs, ?int $userId = null): Collection
    {
        return collect($generatedFaqs)
            ->map(function (array $faq) use ($input, $userId): ?FaqQuestion {
                $question = trim((string) ($faq['question'] ?? ''));
                $answer = trim((string) ($faq['answer'] ?? ''));

                if ($question === '' || $answer === '' || $this->faqs->questionExists($question, $input->locale)) {
                    return null;
                }

                $model = $this->faqs->create([
                    'question' => $question,
                    'answer' => $answer,
                    'language' => $input->locale,
                    'faq_type' => (string) ($faq['faq_type'] ?? $this->resolveFaqType($input)->value),
                    'search_intent' => (string) ($faq['search_intent'] ?? FaqSearchIntent::COMMERCIAL->value),
                    'funnel_stage' => (string) ($faq['funnel_stage'] ?? FaqFunnelStage::CONSIDERATION->value),
                    'priority' => (int) ($faq['priority'] ?? 70),
                    'seo_score' => (float) ($faq['seo_impact'] ?? 70),
                    'ai_visibility_score' => (float) ($faq['ai_visibility_impact'] ?? 70),
                    'conversion_score' => (float) ($faq['conversion_impact'] ?? 70),
                    'is_global' => false,
                    'status' => FaqStatus::PUBLISHED->value,
                    'internal_links' => (array) ($faq['recommended_internal_links'] ?? []),
                    'recommended_cta' => trim((string) ($faq['suggested_cta'] ?? '')),
                    'source_context' => [
                        'page_type' => $input->pageType,
                        'page_slug' => $input->pageSlug,
                        'sector' => $input->sector,
                        'solution_type' => $input->solutionType,
                    ],
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);

                $model->assignments()->create([
                    'page_type' => $input->pageType,
                    'page_slug' => $input->pageSlug,
                    'locale' => $input->locale,
                    'weight' => (int) ($faq['priority'] ?? 70),
                ]);

                return $model;
            })
            ->filter()
            ->values();
    }

    /**
     * @return array<string,array<int,string>>
     */
    private function detectMissingQuestions(FaqPageInput $input, Collection $existingPageFaqs): array
    {
        $text = $input->searchableText();
        $existing = $existingPageFaqs
            ->map(fn (FaqQuestion $faq): string => mb_strtolower($faq->question.' '.$faq->answer))
            ->implode(' ');

        $catalog = [
            'buyer_questions' => [
                'Wat doet Argusly voor kennisintensieve B2B organisaties?' => ['argusly', 'voor wie', 'b2b'],
                'Voor welke teams is deze oplossing bedoeld?' => ['team', 'doelgroep', 'voor wie'],
                'Wanneer is deze pagina relevant voor een buyer?' => ['wanneer', 'use case', 'buyer'],
            ],
            'ai_visibility_questions' => [
                'Hoe helpt dit bij AI Visibility?' => ['ai visibility', 'llm', 'ai answer', 'citation'],
                'Hoe wordt zichtbaar of AI-systemen het merk correct begrijpen?' => ['ai-systemen', 'begrepen', 'mentions'],
                'Welke answer gaps kan Argusly hiermee oplossen?' => ['answer gap', 'answer block', 'faq'],
            ],
            'roi_questions' => [
                'Hoe meet je de ROI van deze aanpak?' => ['roi', 'impact', 'business case'],
                'Welke operationele tijdwinst kan dit opleveren?' => ['tijd', 'efficiency', 'capaciteit'],
                'Welke signalen tonen dat deze investering werkt?' => ['measure', 'meten', 'performance'],
            ],
            'implementation_questions' => [
                'Hoe implementeer je dit naast bestaande workflows?' => ['implementatie', 'workflow', 'setup'],
                'Welke data of input is nodig om te starten?' => ['input', 'data', 'starten'],
                'Hoe snel kan een team hiermee starten?' => ['timeline', 'snel', 'start'],
            ],
            'governance_questions' => [
                'Hoe blijft menselijke controle behouden?' => ['governance', 'approval', 'review'],
                'Hoe voorkomt Argusly generieke AI-output?' => ['kwaliteit', 'brand', 'generic'],
                'Welke rollen moeten betrokken zijn?' => ['rollen', 'stakeholder', 'team'],
            ],
            'comparison_questions' => [
                'Hoe verschilt dit van traditionele SEO of content tooling?' => ['seo', 'verschil', 'tooling'],
                'Hoe verschilt Argusly van een AI writer?' => ['ai writer', 'writer', 'generation'],
                'Wanneer kies je deze oplossing boven losse dashboards?' => ['dashboard', 'analytics', 'reporting'],
            ],
            'competitive_questions' => [
                'Hoe helpt Argusly bij concurrentievragen?' => ['competitor', 'concurrent', 'competitive'],
                'Hoe ontdek je waar concurrenten vaker in AI-antwoorden verschijnen?' => ['concurrenten', 'ai-antwoorden', 'answer share'],
            ],
            'vertical_specific_questions' => [
                'Welke vragen spelen specifiek in '.$this->fallback($input->sector, 'deze sector').'?' => [$input->sector, 'sector', 'industry'],
                'Welke content clusters zijn belangrijk voor '.$this->fallback($input->sector, 'deze markt').'?' => ['cluster', 'topic', $input->sector],
                'Welke proof points verwachten buyers in '.$this->fallback($input->sector, 'deze sector').'?' => ['proof', 'bewijs', $input->sector],
            ],
        ];

        return collect($catalog)
            ->map(function (array $questions) use ($text, $existing): array {
                return collect($questions)
                    ->filter(function (array $signals, string $question) use ($text, $existing): bool {
                        $questionExists = str_contains($existing, mb_strtolower($question));
                        $coveredSignals = collect($signals)
                            ->filter(fn (string $signal): bool => $signal !== '' && str_contains($text, mb_strtolower($signal)))
                            ->count();

                        return ! $questionExists && $coveredSignals < 2;
                    })
                    ->keys()
                    ->values()
                    ->all();
            })
            ->all();
    }

    /**
     * @param  array<string,array<int,string>>  $detected
     * @return array<int,array<string,mixed>>
     */
    private function generateFaqs(FaqPageInput $input, array $detected, Collection $existingPageFaqs): array
    {
        $flat = collect($detected)
            ->flatMap(fn (array $questions, string $category): array => collect($questions)->map(fn (string $question): array => [$category, $question])->all())
            ->reject(fn (array $pair): bool => $this->faqs->questionExists($pair[1], $input->locale))
            ->take(10)
            ->values();

        return $flat
            ->map(function (array $pair, int $index) use ($input): array {
                [$category, $question] = $pair;
                $intent = $this->intentForCategory($category);
                $stage = $this->stageForCategory($category);
                $type = $this->typeForCategory($category, $input);
                $conversion = $this->impactForCategory($category, 'conversion');
                $ai = $this->impactForCategory($category, 'ai');
                $seo = $this->impactForCategory($category, 'seo');

                return [
                    'priority' => 100 - ($index * 4),
                    'question' => $question,
                    'answer' => $this->answerFor($question, $category, $input),
                    'faq_type' => $type->value,
                    'search_intent' => $intent->value,
                    'funnel_stage' => $stage->value,
                    'conversion_impact' => $conversion,
                    'ai_visibility_impact' => $ai,
                    'seo_impact' => $seo,
                    'recommended_internal_links' => $this->linksFor($category, $input),
                    'suggested_cta' => $this->ctaFor($category, $input),
                ];
            })
            ->sortByDesc(fn (array $faq): int => ($faq['conversion_impact'] * 3) + ($faq['ai_visibility_impact'] * 2) + $faq['seo_impact'])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,array<int,string>>  $detected
     * @param  array<int,array<string,mixed>>  $generated
     * @return array<string,array<string,mixed>>
     */
    private function scores(FaqPageInput $input, Collection $existingPageFaqs, array $detected, array $generated): array
    {
        $missingCount = collect($detected)->flatten()->count();
        $existingCount = $existingPageFaqs->count();
        $coverage = max(0, min(100, ($existingCount * 12) - ($missingCount * 4) + 35));
        $opportunity = max(0, min(100, 100 - $coverage + min(30, count($generated) * 4)));
        $ai = max(0, min(100, 45 + count($detected['ai_visibility_questions'] ?? []) * 12 + count($detected['vertical_specific_questions'] ?? []) * 4));
        $seo = max(0, min(100, 40 + $missingCount * 5 + (count($input->h2s) < 4 ? 8 : 0)));
        $conversion = max(0, min(100, 35 + count($detected['buyer_questions'] ?? []) * 8 + count($detected['roi_questions'] ?? []) * 12 + count($detected['governance_questions'] ?? []) * 8));

        return [
            'faq_coverage_score' => [
                'score' => round($coverage, 2),
                'rationale' => "Found {$existingCount} active page FAQ(s) and {$missingCount} missing question opportunities.",
            ],
            'faq_opportunity_score' => [
                'score' => round($opportunity, 2),
                'rationale' => 'Opportunity rises when coverage is low and generated FAQ candidates are commercially relevant.',
            ],
            'ai_visibility_impact_score' => [
                'score' => round($ai, 2),
                'rationale' => 'Score reflects missing AI answer, entity, citation and vertical prompt coverage.',
            ],
            'seo_impact_score' => [
                'score' => round($seo, 2),
                'rationale' => 'Score reflects long-tail question gaps, semantic completeness and heading coverage.',
            ],
            'conversion_impact_score' => [
                'score' => round($conversion, 2),
                'rationale' => 'Score reflects unanswered buyer objections, ROI, governance and decision-stage questions.',
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $generated
     * @return Collection<int,FaqQuestion>
     */
    private function generatedToModels(array $generated, FaqPageInput $input): Collection
    {
        return collect($generated)->map(function (array $faq) use ($input): FaqQuestion {
            $model = new FaqQuestion([
                'question' => (string) $faq['question'],
                'answer' => (string) $faq['answer'],
                'language' => $input->locale,
                'faq_type' => (string) $faq['faq_type'],
                'search_intent' => (string) $faq['search_intent'],
                'funnel_stage' => (string) $faq['funnel_stage'],
                'status' => FaqStatus::PUBLISHED->value,
            ]);

            return $model;
        });
    }

    private function answerFor(string $question, string $category, FaqPageInput $input): string
    {
        $sector = $this->fallback($input->sector, 'kennisintensieve B2B organisaties');
        $solution = $this->fallback($input->solutionType, 'AI Visibility en opportunity intelligence');
        $humanSignalSentence = $this->humanSignalSentence($input);

        $answer = match ($category) {
            'ai_visibility_questions' => "Argusly helpt hierbij door de vraag te vertalen naar meetbare AI Visibility signalen: wordt de organisatie genoemd, correct begrepen, geciteerd en gekoppeld aan de juiste buyer questions. Voor {$sector} betekent dit dat content niet alleen op rankings wordt beoordeeld, maar ook op answer readiness, entity clarity, interne links en FAQPage schema dat AI-systemen eenvoudiger kunnen verwerken.",
            'roi_questions' => "De ROI wordt zichtbaar door minder handmatig analysewerk, betere prioritering en meer impact per contentactie. Argusly koppelt {$solution} aan concrete signalen zoals gemiste vragen, competitor gaps, content refresh kansen, AI answer coverage en conversiepunten. Daardoor kan een team beoordelen welke FAQ, pagina-update of workflow waarschijnlijk bijdraagt aan pipeline, zichtbaarheid of operationele efficiëntie.",
            'implementation_questions' => "Implementatie begint met een compacte analyse van de bestaande pagina, interne links, sectorcontext en solution type. Argusly bepaalt daarna welke vragen ontbreken, welke FAQ's al bestaan en welke nieuwe antwoorden veilig kunnen worden toegevoegd. Teams kunnen gegenereerde FAQ's reviewen, accepteren, publiceren en daarna meten zonder de pagina handmatig opnieuw te structureren.",
            'governance_questions' => "Menselijke controle blijft behouden doordat Argusly FAQ-kansen detecteert en conceptantwoorden voorbereidt, maar publicatie via review en statusbeheer laat lopen. Governance ontstaat uit centrale opslag, page assignments, prioriteit, schema-validatie en meetbare scores. Zo kan een team autonoom marketingwerk organiseren zonder generieke AI-output direct live te zetten.",
            'comparison_questions' => "Het verschil zit in de stap van losse analyse naar uitvoerbare marketingactie. Traditionele SEO- of contenttools tonen vaak data; Argusly bepaalt welke vraag ontbreekt, waarom die commercieel belangrijk is en hoe het antwoord AI Visibility, semantic SEO en conversie ondersteunt. Daardoor ontstaat een workflow van detectie naar creatie, publicatie en meting.",
            'competitive_questions' => "Argusly helpt concurrentievragen beantwoorden door te kijken welke buyer questions, topics en AI-answer posities nog onvoldoende door de pagina worden afgedekt. Als concurrenten vaker verschijnen in AI-antwoorden, wijst dat meestal op betere entity coverage, sterkere proof of meer directe antwoorden. De FAQ Intelligence Engine vertaalt dat naar concrete FAQ-kansen.",
            'vertical_specific_questions' => "Voor {$sector} zijn FAQ's vooral waardevol wanneer ze sectorspecifieke buyer objections, implementatie-eisen en bewijsverwachtingen beantwoorden. Argusly gebruikt sector en solution type om generieke antwoorden te vermijden en vragen te maken rond marktcontext, governance, ROI, integraties en AI Visibility. Zo wordt de pagina relevanter voor zowel buyers als AI-systemen.",
            default => "Argusly beantwoordt deze vraag door contentkansen te koppelen aan buyer intent, AI Visibility, semantic SEO en conversie. Voor {$sector} betekent dit dat ontbrekende vragen niet als losse copy worden behandeld, maar als signalen in een workflow: detecteren, prioriteren, FAQ's creëren, publiceren en daarna meten welke antwoorden bijdragen aan zichtbaarheid en betere besluitvorming.",
        };

        return trim($answer.$humanSignalSentence);
    }

    private function humanSignalSentence(FaqPageInput $input): string
    {
        $workspaceId = $input->workspaceId;
        if (! $workspaceId && $input->siteId) {
            $workspaceId = \App\Models\ClientSite::query()->whereKey($input->siteId)->value('workspace_id');
        }

        if (! $workspaceId) {
            return '';
        }

        $workspace = Workspace::query()->find($workspaceId);
        $signals = $this->humanSignalContext->forWorkspace($workspace, 2);
        if ($signals === '') {
            return '';
        }

        $firstSignal = collect(explode("\n", $signals))
            ->first(fn (string $line): bool => str_starts_with(trim($line), '- ['));

        return $firstSignal ? ' Deze FAQ is gebaseerd op het waargenomen signaal: '.trim(ltrim((string) $firstSignal, '- ')).'.' : '';
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function linksFor(string $category, FaqPageInput $input): array
    {
        $links = match ($category) {
            'ai_visibility_questions' => [
                ['label' => 'AI Visibility', 'route' => 'public.solutions.ai-visibility'],
                ['label' => 'Contact', 'route' => 'public.company.contact'],
            ],
            'competitive_questions', 'comparison_questions' => [
                ['label' => 'Competitive Intelligence', 'route' => 'public.solutions.competitive-intelligence'],
                ['label' => 'Opportunity Intelligence', 'route' => 'public.solutions.opportunity-intelligence'],
            ],
            'implementation_questions', 'governance_questions' => [
                ['label' => 'Platform', 'route' => 'public.product.platform'],
                ['label' => 'Security', 'route' => 'public.legal.security'],
            ],
            'roi_questions' => [
                ['label' => 'Pricing', 'route' => 'pricing'],
                ['label' => 'Contact', 'route' => 'public.company.contact'],
            ],
            default => [
                ['label' => 'Opportunity Intelligence', 'route' => 'public.solutions.opportunity-intelligence'],
                ['label' => 'Agentic Marketing', 'route' => 'public.agentic-marketing'],
            ],
        };

        return $links;
    }

    private function ctaFor(string $category, FaqPageInput $input): string
    {
        return match ($category) {
            'ai_visibility_questions' => 'Vraag een AI Visibility Scan aan',
            'roi_questions' => 'Bespreek de business case',
            'implementation_questions', 'governance_questions' => 'Plan een platform demo',
            'competitive_questions', 'comparison_questions' => 'Bekijk je competitive gaps',
            default => 'Ontdek de belangrijkste FAQ kansen',
        };
    }

    /**
     * @param  array<int,array<string,mixed>>  $generated
     * @return array<int,array<string,mixed>>
     */
    private function internalLinkOpportunities(FaqPageInput $input, array $generated): array
    {
        return collect($generated)
            ->flatMap(fn (array $faq): array => (array) ($faq['recommended_internal_links'] ?? []))
            ->unique(fn (array $link): string => (string) ($link['route'] ?? $link['label'] ?? ''))
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function suggestedCtas(FaqPageInput $input): array
    {
        return array_values(array_unique([
            'Vraag een AI Visibility Scan aan',
            'Plan een platform demo',
            'Bekijk je competitive gaps',
            'Bespreek de business case',
        ]));
    }

    private function intentForCategory(string $category): FaqSearchIntent
    {
        return match ($category) {
            'comparison_questions', 'competitive_questions' => FaqSearchIntent::COMPARISON,
            'roi_questions', 'governance_questions', 'implementation_questions' => FaqSearchIntent::COMMERCIAL,
            default => FaqSearchIntent::INFORMATIONAL,
        };
    }

    private function stageForCategory(string $category): FaqFunnelStage
    {
        return match ($category) {
            'roi_questions', 'governance_questions' => FaqFunnelStage::DECISION,
            'implementation_questions', 'comparison_questions', 'competitive_questions' => FaqFunnelStage::CONSIDERATION,
            default => FaqFunnelStage::AWARENESS,
        };
    }

    private function typeForCategory(string $category, FaqPageInput $input): FaqType
    {
        return match ($category) {
            'ai_visibility_questions' => FaqType::SOLUTION,
            'roi_questions' => FaqType::PRICING,
            'implementation_questions' => FaqType::IMPLEMENTATION,
            'governance_questions' => FaqType::GOVERNANCE,
            'comparison_questions', 'competitive_questions' => FaqType::COMPARISON,
            'vertical_specific_questions' => FaqType::MARKET,
            default => $this->resolveFaqType($input),
        };
    }

    private function resolveFaqType(FaqPageInput $input): FaqType
    {
        return FaqType::tryFrom($input->pageType) ?? FaqType::RESOURCE;
    }

    private function impactForCategory(string $category, string $axis): int
    {
        $base = [
            'buyer_questions' => ['conversion' => 82, 'ai' => 70, 'seo' => 76],
            'ai_visibility_questions' => ['conversion' => 76, 'ai' => 94, 'seo' => 84],
            'roi_questions' => ['conversion' => 92, 'ai' => 68, 'seo' => 76],
            'implementation_questions' => ['conversion' => 86, 'ai' => 70, 'seo' => 72],
            'governance_questions' => ['conversion' => 88, 'ai' => 72, 'seo' => 70],
            'comparison_questions' => ['conversion' => 84, 'ai' => 76, 'seo' => 86],
            'competitive_questions' => ['conversion' => 78, 'ai' => 86, 'seo' => 82],
            'vertical_specific_questions' => ['conversion' => 80, 'ai' => 84, 'seo' => 90],
        ];

        return (int) ($base[$category][$axis] ?? 72);
    }

    private function fallback(string $value, string $fallback): string
    {
        return trim($value) !== '' ? trim($value) : $fallback;
    }
}
