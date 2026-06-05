<?php

namespace App\Services\AgenticMarketing\CampaignOrchestration;

use App\Models\AgenticMarketingOpportunity;
use App\Services\AgenticMarketing\StrategicPlanning\StrategicPlanningEngine;
use Illuminate\Support\Str;

class AutonomousCampaignOrchestrationEngine
{
    public function __construct(
        private readonly StrategicPlanningEngine $strategicPlanningEngine,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function planForOpportunity(AgenticMarketingOpportunity $opportunity): array
    {
        $cluster = $this->strategicPlanningEngine->clusterProposalForOpportunity($opportunity);
        $topic = (string) ($cluster['topic'] ?? data_get($opportunity->payload, 'topic', $opportunity->title));
        $articles = $this->articles($topic, (array) ($cluster['missing'] ?? []));

        return [
            'schema' => 'agentic_marketing.autonomous_campaign_orchestration.v1',
            'campaign' => '30 day Agentic Marketing Campaign',
            'topic' => $topic,
            'duration_days' => 30,
            'objective' => $opportunity->objective?->goal,
            'operating_model' => 'HubSpot + SEO platform + AI orchestration layer',
            'articles' => $articles,
            'linkedin_posts' => $this->linkedinPosts($topic, $articles),
            'refresh_schedule' => $this->refreshSchedule($articles),
            'interlink_map' => $this->interlinkMap($articles),
            'cta_strategy' => $this->ctaStrategy($topic),
            'republishing_cadence' => [
                'cadence' => 'weekly',
                'days' => [7, 14, 21, 30],
                'rule' => 'Republish only after approved diff, metadata, schema, and internal link checks pass.',
            ],
            'geo_optimization' => $this->geoOptimization($topic),
            'ai_visibility_monitoring' => $this->aiVisibilityMonitoring($topic),
            'approval_gates' => [
                'Approve campaign structure before article generation.',
                'Approve each content diff before republishing.',
                'Approve CTA and LinkedIn copy before scheduling.',
            ],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $missing
     * @return array<int,array<string,mixed>>
     */
    private function articles(string $topic, array $missing): array
    {
        $fallback = [
            ['type' => 'pillar_page', 'recommended_title' => $topic.' Authority Hub', 'reason' => 'Central hub for the campaign.'],
            ['type' => 'glossary', 'recommended_title' => $topic.' Glossary', 'reason' => 'Define core entities.'],
            ['type' => 'comparison_pages', 'recommended_title' => $topic.' Platform Comparison', 'reason' => 'Capture evaluative intent.'],
            ['type' => 'faq_hub', 'recommended_title' => $topic.' FAQ Hub', 'reason' => 'Build answer-ready coverage.'],
            ['type' => 'case_study', 'recommended_title' => $topic.' Case Study', 'reason' => 'Add proof and outcomes.'],
            ['type' => 'implementation_guide', 'recommended_title' => $topic.' Implementation Guide', 'reason' => 'Turn strategy into workflow.'],
            ['type' => 'tooling_comparison', 'recommended_title' => $topic.' Tooling Comparison', 'reason' => 'Support buying decisions.'],
            ['type' => 'enterprise_governance_article', 'recommended_title' => 'Enterprise '.$topic.' Governance', 'reason' => 'Cover ownership and risk.'],
        ];

        $items = array_values(array_merge([[
            'type' => 'pillar_page',
            'recommended_title' => $topic.' Authority Hub',
            'reason' => 'Central hub for the 30 day campaign.',
        ]], $missing ?: $fallback));

        return collect($items)
            ->take(8)
            ->values()
            ->map(fn (array $item, int $index): array => [
                'order' => $index + 1,
                'type' => (string) ($item['type'] ?? 'article'),
                'title' => (string) ($item['recommended_title'] ?? $topic.' Article '.($index + 1)),
                'publish_day' => [1, 4, 7, 11, 15, 19, 24, 28][$index] ?? min(30, ($index + 1) * 4),
                'purpose' => (string) ($item['reason'] ?? 'Build campaign authority.'),
                'geo_task' => 'Add answer blocks, entity coverage, FAQ schema, and citation-ready summaries.',
                'primary_cta' => $index < 3 ? 'Explore the '.$topic.' hub' : 'Book an Agentic Marketing demo',
            ])
            ->all();
    }

    /**
     * @param array<int,array<string,mixed>> $articles
     * @return array<int,array<string,mixed>>
     */
    private function linkedinPosts(string $topic, array $articles): array
    {
        return collect($articles)
            ->flatMap(fn (array $article): array => [
                [
                    'day' => max(1, (int) $article['publish_day']),
                    'article_order' => $article['order'],
                    'angle' => 'Launch insight',
                    'hook' => $article['title'].' is now the next building block in our '.$topic.' authority campaign.',
                ],
                [
                    'day' => min(30, (int) $article['publish_day'] + 2),
                    'article_order' => $article['order'],
                    'angle' => 'Question-led follow-up',
                    'hook' => 'The strategic question behind '.$article['title'].': what would make this easier for AI systems to cite?',
                ],
            ])
            ->values()
            ->take(16)
            ->all();
    }

    /**
     * @param array<int,array<string,mixed>> $articles
     * @return array<int,array<string,mixed>>
     */
    private function refreshSchedule(array $articles): array
    {
        return collect($articles)
            ->map(fn (array $article): array => [
                'article_order' => $article['order'],
                'review_day' => min(30, (int) $article['publish_day'] + 14),
                'checks' => ['freshness decay', 'AI visibility score', 'answer readiness', 'internal link coverage'],
            ])
            ->all();
    }

    /**
     * @param array<int,array<string,mixed>> $articles
     * @return array<string,mixed>
     */
    private function interlinkMap(array $articles): array
    {
        $hub = $articles[0]['title'] ?? 'Campaign hub';

        return [
            'model' => 'hub_and_spoke_plus_lateral_decision_links',
            'hub' => $hub,
            'rules' => [
                'Every article links back to the authority hub.',
                'The hub links to all eight campaign articles.',
                'Comparison and governance articles link laterally to implementation and tooling pages.',
                'FAQ and glossary pages support every answer block with entity-match anchors.',
            ],
            'links' => collect($articles)
                ->skip(1)
                ->map(fn (array $article): array => [
                    'from' => $article['title'],
                    'to' => $hub,
                    'anchor_text' => Str::lower((string) $article['type']).' for '.Str::lower($hub),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function ctaStrategy(string $topic): array
    {
        return [
            'awareness' => 'Explore the '.$topic.' hub',
            'consideration' => 'Compare '.$topic.' workflows',
            'decision' => 'Book an Agentic Marketing demo',
            'placement_rules' => [
                'Use soft CTA after the first answer block.',
                'Use product CTA after implementation sections.',
                'Use demo CTA on comparison, tooling, governance, and case-study pages.',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function geoOptimization(string $topic): array
    {
        return [
            'target' => $topic,
            'tasks' => [
                'Generate answer blocks for each article.',
                'Add FAQ schema where query intent is question-led.',
                'Add entity-rich summaries for AI answer extraction.',
                'Refresh metadata around answer intent and category language.',
                'Check citation likelihood before republish.',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function aiVisibilityMonitoring(string $topic): array
    {
        return [
            'cadence' => 'weekly during campaign, biweekly after day 30',
            'queries' => [
                'What is '.$topic.'?',
                'Best '.$topic.' tools',
                $topic.' implementation guide',
                $topic.' governance for enterprise teams',
            ],
            'metrics' => ['AI discoverability', 'answer readiness', 'citation likelihood', 'competitor overlap', 'freshness decay'],
        ];
    }
}
