<?php

namespace App\Services\ContentOpportunityEngine;

use Illuminate\Support\Str;

class ContentOpportunityCandidateGenerator
{
    /**
     * @return array<int, ContentOpportunityCandidate>
     */
    public function generate(array $input): array
    {
        $candidates = collect()
            ->merge($this->fromCompanyIntelligence($input))
            ->merge($this->fromCompetitorIntelligence($input))
            ->merge($this->fromContentInventory($input))
            ->merge($this->campaignClusters($input));

        if ($candidates->isEmpty()) {
            $candidates = $candidates->merge($this->fromWorkspaceFallback($input));
        }

        return $candidates
            ->unique(fn (ContentOpportunityCandidate $candidate): string => $candidate->type . '|' . Str::lower($candidate->title))
            ->take(80)
            ->values()
            ->all();
    }

    /**
     * @return array<int, ContentOpportunityCandidate>
     */
    private function fromCompanyIntelligence(array $input): array
    {
        $company = (array) data_get($input, 'company.company_intelligence', []);
        $topics = $this->strings((array) data_get($company, 'seo_aeo.primary_topics', data_get($company, 'business.primary_topics', [])));
        $entities = $this->strings((array) data_get($company, 'seo_aeo.target_entities', []));
        $audiences = $this->strings((array) data_get($company, 'audience.buyer_roles', data_get($company, 'audience.personas', [])));
        $useCases = $this->strings((array) data_get($company, 'business.products_services', []));
        $proof = $this->strings((array) data_get($company, 'brand.proof_points', []));

        $items = [];
        foreach (array_slice($topics, 0, 8) as $topic) {
            $items[] = new ContentOpportunityCandidate(
                type: 'article_idea',
                title: $this->title('How to improve ' . $topic),
                topic: $topic,
                reasoning: 'Company intelligence marks this as a primary topic or authority area.',
                angle: 'Own the educational narrative with entity-rich answers and practical examples.',
                sourceSignals: ['source' => 'company_intelligence', 'topic' => $topic],
                relatedEntities: $entities,
                targetAudience: $audiences[0] ?? 'marketers',
                suggestedCta: 'Explore Argusly workflows',
                suggestedSchema: 'Article',
            );
            $items[] = new ContentOpportunityCandidate(
                type: 'faq_opportunity',
                title: $this->title($topic . ' FAQ'),
                topic: $topic,
                reasoning: 'Primary topics should have explicit answer coverage for AI and search surfaces.',
                angle: 'Answer recurring objections and setup questions in concise blocks.',
                sourceSignals: ['source' => 'company_intelligence', 'topic' => $topic],
                relatedEntities: $entities,
                targetAudience: $audiences[0] ?? 'marketers',
                suggestedCta: 'Request a walkthrough',
                suggestedSchema: 'FAQPage',
            );
        }

        foreach (array_slice($useCases, 0, 5) as $useCase) {
            $items[] = new ContentOpportunityCandidate(
                type: 'use_case_page',
                title: $this->title($useCase . ' use case'),
                topic: $useCase,
                reasoning: 'Products and services should map to commercial use-case pages for consideration and decision-stage demand.',
                angle: 'Connect pains, triggers, proof, and workflow outcomes.',
                sourceSignals: ['source' => 'company_intelligence', 'proof_points' => $proof],
                relatedEntities: $entities,
                targetAudience: $audiences[0] ?? 'operations',
                searchIntent: 'commercial',
                suggestedCta: 'See the use case in action',
                suggestedSchema: 'WebPage',
            );
        }

        return $items;
    }

    /**
     * @return array<int, ContentOpportunityCandidate>
     */
    private function fromCompetitorIntelligence(array $input): array
    {
        $items = [];

        foreach (array_slice((array) ($input['competitor_opportunities'] ?? []), 0, 30) as $opportunity) {
            $topic = (string) ($opportunity['topic'] ?? $opportunity['title'] ?? '');
            if ($topic === '') {
                continue;
            }

            $type = match ((string) ($opportunity['type'] ?? '')) {
                'comparison_page' => 'comparison_page',
                'missing_bofu_page' => 'bofu_page',
                'implementation_guide' => 'implementation_guide',
                'use_case_page' => 'use_case_page',
                'answer_block_gap' => 'answer_block_opportunity',
                default => 'article_idea',
            };

            $items[] = new ContentOpportunityCandidate(
                type: $type,
                title: $this->title((string) ($opportunity['title'] ?? 'Create content for ' . $topic)),
                topic: $topic,
                reasoning: (string) ($opportunity['reason'] ?? 'Competitor intelligence found a missing or weak content gap.'),
                angle: (string) ($opportunity['attackable_angle'] ?? 'Create stronger, more specific coverage than competitor pages.'),
                sourceSignals: ['source' => 'competitor_intelligence', 'opportunity' => $opportunity],
                relatedEntities: array_keys((array) data_get($opportunity, 'competitor_evidence.entities', [])),
                targetAudience: 'marketers',
                funnelStage: $opportunity['funnel_stage'] ?? null,
                searchIntent: $opportunity['query_intent'] ?? null,
                suggestedCta: in_array($type, ['comparison_page', 'bofu_page'], true) ? 'Book a demo' : 'Explore the workflow',
                suggestedSchema: $type === 'answer_block_opportunity' ? 'FAQPage' : 'Article',
            );
        }

        foreach (array_slice((array) ($input['competitor_topics'] ?? []), 0, 20) as $signal) {
            $topic = (string) ($signal['topic'] ?? '');
            if ($topic === '') {
                continue;
            }

            $items[] = new ContentOpportunityCandidate(
                type: ((string) ($signal['coverage_status'] ?? 'missing')) === 'weak' ? 'refresh_opportunity' : 'article_idea',
                title: $this->title('Close the gap on ' . $topic),
                topic: $topic,
                reasoning: 'Competitor topic overlap indicates missing or weak Argusly coverage.',
                angle: 'Publish a more useful answer with proof, examples, schema, and internal links.',
                sourceSignals: ['source' => 'competitor_topic_signal', 'signal' => $signal],
                relatedEntities: array_keys((array) ($signal['entities'] ?? [])),
                targetAudience: 'marketers',
                suggestedCta: 'Review your content gaps',
                suggestedSchema: 'Article',
            );
        }

        return $items;
    }

    /**
     * @return array<int, ContentOpportunityCandidate>
     */
    private function fromContentInventory(array $input): array
    {
        $items = [];
        foreach ((array) ($input['content_inventory'] ?? []) as $content) {
            $title = (string) ($content['title'] ?? '');
            if ($title === '') {
                continue;
            }

            $health = (int) ($content['content_health_score'] ?? 100);
            $aeo = (int) ($content['aeo_score'] ?? 100);
            $visibility = (int) ($content['ai_visibility_score'] ?? 100);

            if ($health < 65 || $aeo < 65 || $visibility < 55) {
                $items[] = new ContentOpportunityCandidate(
                    type: 'refresh_opportunity',
                    title: $this->title('Refresh ' . $title),
                    topic: (string) ($content['primary_keyword'] ?? $title),
                    reasoning: 'Lifecycle and SEO/AEO scores indicate this existing content can support a new refresh opportunity.',
                    angle: 'Improve freshness, answer coverage, internal links, and AI visibility signals.',
                    sourceSignals: ['source' => 'content_inventory', 'content' => $content],
                    relatedEntities: [],
                    targetAudience: 'marketers',
                    suggestedCta: 'Read the updated guide',
                    suggestedSchema: 'Article',
                );
            }
        }

        return $items;
    }

    /**
     * @return array<int, ContentOpportunityCandidate>
     */
    private function campaignClusters(array $input): array
    {
        $company = (array) data_get($input, 'company.company_intelligence', []);
        $topics = $this->strings((array) data_get($company, 'seo_aeo.primary_topics', []));
        $entities = $this->strings((array) data_get($company, 'seo_aeo.target_entities', []));

        return collect($topics)
            ->take(4)
            ->map(fn (string $topic): ContentOpportunityCandidate => new ContentOpportunityCandidate(
                type: 'campaign_cluster',
                title: $this->title($topic . ' campaign cluster'),
                topic: $topic,
                reasoning: 'A strategic topic can become a cluster spanning awareness, consideration, decision, and retention assets.',
                angle: 'Create a connected content system with article, FAQ, comparison, implementation, and BOFU assets.',
                sourceSignals: ['source' => 'company_intelligence', 'cluster_topic' => $topic],
                relatedEntities: $entities,
                targetAudience: 'marketers',
                suggestedCta: 'Plan this campaign',
                suggestedSchema: 'CollectionPage',
            ))
            ->values()
            ->all();
    }

    /**
     * @return array<int, ContentOpportunityCandidate>
     */
    private function fromWorkspaceFallback(array $input): array
    {
        $topics = collect($this->fallbackTopics($input))->take(4);

        return $topics
            ->flatMap(fn (string $topic): array => [
                new ContentOpportunityCandidate(
                    type: 'article_idea',
                    title: $this->title('Improve ' . $topic . ' coverage'),
                    topic: $topic,
                    reasoning: 'No dedicated intelligence inputs were available, so the engine used workspace, site, and content inventory context to seed an initial opportunity.',
                    angle: 'Create a practical, entity-rich page that establishes baseline coverage and gives future intelligence runs a stronger source to refine.',
                    sourceSignals: ['source' => 'workspace_fallback', 'context' => data_get($input, 'workspace_context', [])],
                    relatedEntities: [$topic],
                    targetAudience: 'marketers',
                    suggestedCta: 'Explore Argusly',
                    suggestedSchema: 'Article',
                ),
                new ContentOpportunityCandidate(
                    type: 'faq_opportunity',
                    title: $this->title($topic . ' FAQ'),
                    topic: $topic,
                    reasoning: 'A starter FAQ creates answer coverage for search and AI surfaces while deeper intelligence is still being collected.',
                    angle: 'Answer setup, comparison, implementation, and buying questions in concise blocks.',
                    sourceSignals: ['source' => 'workspace_fallback', 'context' => data_get($input, 'workspace_context', [])],
                    relatedEntities: [$topic],
                    targetAudience: 'marketers',
                    suggestedCta: 'Request a walkthrough',
                    suggestedSchema: 'FAQPage',
                ),
                new ContentOpportunityCandidate(
                    type: 'campaign_cluster',
                    title: $this->title($topic . ' campaign cluster'),
                    topic: $topic,
                    reasoning: 'A connected starter cluster gives the workspace an actionable plan before competitor and company intelligence are complete.',
                    angle: 'Plan pillar, FAQ, comparison, implementation, and internal-linking assets around the same entity.',
                    sourceSignals: ['source' => 'workspace_fallback', 'context' => data_get($input, 'workspace_context', [])],
                    relatedEntities: [$topic],
                    targetAudience: 'marketers',
                    suggestedCta: 'Plan this campaign',
                    suggestedSchema: 'CollectionPage',
                ),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function fallbackTopics(array $input): array
    {
        return collect()
            ->merge(collect((array) ($input['content_inventory'] ?? []))->pluck('primary_keyword'))
            ->merge(collect((array) ($input['content_inventory'] ?? []))->pluck('title'))
            ->merge(collect((array) ($input['content_clusters'] ?? []))->pluck('name'))
            ->merge(collect((array) ($input['content_clusters'] ?? []))->pluck('topic'))
            ->merge([
                data_get($input, 'workspace_context.site_name'),
                data_get($input, 'workspace_context.name'),
            ])
            ->map(fn (mixed $topic): string => $this->normalizeTopic((string) $topic))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeTopic(string $topic): string
    {
        $normalized = Str::of($topic)
            ->replaceMatches('/[|:;]+/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->lower()
            ->value();

        if ($normalized === '') {
            return '';
        }

        return Str::words($normalized, 8, '');
    }

    private function title(string $value): string
    {
        return Str::headline(Str::limit(trim($value), 120, ''));
    }

    private function strings(array $items): array
    {
        return collect($items)
            ->map(fn (mixed $item): string => trim(is_array($item) ? (string) ($item['name'] ?? $item['label'] ?? reset($item)) : (string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
