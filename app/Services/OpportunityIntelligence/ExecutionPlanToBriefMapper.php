<?php

namespace App\Services\OpportunityIntelligence;

use App\Enums\OpportunityCategory;
use App\Models\Content;
use App\Models\OpportunityExecutionPlan;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

class ExecutionPlanToBriefMapper
{
    private const SEARCH_INTENTS = ['informational', 'commercial', 'comparison', 'transactional', 'navigational'];

    /**
     * @return array<string,mixed>
     */
    public function map(OpportunityExecutionPlan $plan, User $user, string $clientSiteId): array
    {
        $plan->loadMissing(['opportunity.signals', 'opportunity.content', 'clientSite']);

        $sourceContext = $this->sourceContext($plan);
        $contentType = $this->contentTypeFor((string) $plan->recommended_format);
        $primaryKeyword = $this->primaryKeyword($plan);
        $secondaryKeywords = $this->secondaryKeywords($plan, $primaryKeyword);
        $searchIntent = $this->searchIntent($plan, $primaryKeyword);
        $funnelStage = $this->funnelStage($searchIntent);
        $audiences = $this->audiences($plan, $primaryKeyword);
        $entities = $this->entities($plan, $primaryKeyword, $secondaryKeywords);
        $keyQuestions = $this->keyQuestions($plan, $primaryKeyword, $entities);
        $keyPoints = $this->keyPoints($plan, $primaryKeyword);
        $internalLinks = $this->recommendedInternalLinks($plan, $clientSiteId, $primaryKeyword, $secondaryKeywords, $entities);
        $externalReferences = $this->externalReferences($primaryKeyword, $entities);
        $schemaRecommendations = $this->schemaRecommendations($contentType, $searchIntent, $keyQuestions);
        $faqSuggestions = $this->faqSuggestions($keyQuestions, $primaryKeyword, $entities);
        $objectives = $this->objectives($plan, $primaryKeyword);
        $cta = $this->callToAction($plan, $searchIntent);
        $editorialTitle = $this->editorialTitle($plan, $primaryKeyword, $searchIntent);
        $this->assertQualityGates($editorialTitle, $primaryKeyword, $secondaryKeywords, $entities, $keyPoints, $cta);

        $editorialBrief = [
            'objectives' => $objectives,
            'search_intent' => $searchIntent,
            'funnel_stage' => $funnelStage,
            'key_questions' => $keyQuestions,
            'recommended_internal_links' => $internalLinks,
            'recommended_external_references' => $externalReferences,
            'entity_coverage' => $entities,
            'schema_recommendations' => $schemaRecommendations,
            'faq_suggestions' => $faqSuggestions,
            'distribution_suggestions' => $this->distributionSuggestions($searchIntent),
            'success_metrics' => $this->successMetrics($plan),
            'humanization_notes' => $this->humanizationNotes(),
            'quality_gates' => $this->qualityGates(),
        ];

        $sourceContext = array_merge($sourceContext, [
            'taxonomy' => [
                'intent_keys' => array_values(array_filter([$primaryKeyword, ...array_slice($secondaryKeywords, 0, 5)])),
                'audience_keys' => $audiences,
            ],
            'primary_keyword' => $primaryKeyword,
            'secondary_keywords' => $secondaryKeywords,
            'objectives' => $objectives,
            'key_questions' => $keyQuestions,
            'recommended_internal_links' => $internalLinks,
            'recommended_external_references' => $externalReferences,
            'entity_coverage' => $entities,
            'schema_recommendations' => $schemaRecommendations,
            'faq_questions' => $faqSuggestions,
            'distribution_suggestions' => $editorialBrief['distribution_suggestions'],
            'success_metrics' => $editorialBrief['success_metrics'],
            'humanization_notes' => $editorialBrief['humanization_notes'],
            'editorial_brief' => $editorialBrief,
        ]);

        return [
            'client_site_id' => $clientSiteId,
            'created_by_user_id' => (int) $user->id,
            'status' => 'draft',
            'source' => 'opportunity_execution_plan',
            'title' => $editorialTitle,
            'language' => 'nl',
            'content_type' => $contentType,
            'output_type' => $contentType,
            'intent' => Str::headline($searchIntent),
            'primary_keyword' => $primaryKeyword,
            'secondary_keywords' => $secondaryKeywords,
            'audience' => implode(', ', array_slice($audiences, 0, 4)),
            'target_audience' => implode(', ', $audiences),
            'funnel_stage' => $funnelStage,
            'search_intent' => $searchIntent,
            'tone_of_voice' => $this->toneOfVoice($searchIntent),
            'unique_angle' => $this->uniqueAngle($plan, $primaryKeyword, $searchIntent),
            'key_points' => $keyPoints,
            'call_to_action' => $cta,
            'notes' => $this->notes($plan, $sourceContext),
            'progress' => 0,
            'client_refs' => $sourceContext,
            'wp_site_id' => $clientSiteId,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function sourceContext(OpportunityExecutionPlan $plan): array
    {
        $signalDetectionIds = collect(data_get($plan->source_evidence, 'signals', []))
            ->pluck('signal_detection_id')
            ->filter()
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        $signalEventIds = collect($plan->opportunity?->signals ?? [])
            ->flatMap(fn ($signal): array => (array) data_get($signal, 'metadata.linked_signal_event_ids', []))
            ->filter()
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        return [
            'client_type' => 'opportunity_execution_plan',
            'source' => 'opportunity_intelligence',
            'execution_plan_id' => (string) $plan->id,
            'opportunity_execution_plan_id' => (string) $plan->id,
            'opportunity_id' => (string) $plan->opportunity_id,
            'workspace_id' => (string) $plan->workspace_id,
            'signal_detection_ids' => $signalDetectionIds,
            'signal_event_ids' => $signalEventIds,
            'evidence_summary' => data_get($plan->source_evidence, 'summary', []),
            'planned_steps' => array_values((array) $plan->planned_steps),
            'recommended_actions' => array_values((array) ($plan->opportunity?->recommended_actions ?? [])),
        ];
    }

    private function contentTypeFor(string $format): string
    {
        return match ($format) {
            'comparison_content_and_social_draft' => 'comparison',
            'short_insight_and_blog_brief', 'content_refresh_with_supporting_post' => 'blog',
            default => 'article',
        };
    }

    /**
     * @return array<int,string>
     */
    private function audiences(OpportunityExecutionPlan $plan, string $primaryKeyword): array
    {
        $category = $this->category($plan);
        $text = $this->normalizedHaystack($plan, [$primaryKeyword]);

        $audiences = match ($category) {
            OpportunityCategory::COMPETITOR_MOVEMENT->value => ['B2B buyers comparing alternatives', 'Marketing Directors', 'Growth Teams'],
            OpportunityCategory::AI_VISIBILITY_OPPORTUNITY->value, OpportunityCategory::BRAND_VISIBILITY->value => ['Marketing Directors', 'CMOs', 'SEO Specialists', 'Content Strategists', 'Growth Teams', 'Enterprise Marketing', 'Digital Agencies'],
            default => ['Marketing Directors', 'Content Strategists', 'Growth Teams'],
        };

        if (str_contains($text, 'enterprise')) {
            array_unshift($audiences, 'Enterprise Marketing');
        }

        if (str_contains($text, 'agency') || str_contains($text, 'agencies')) {
            $audiences[] = 'Digital Agencies';
        }

        return $this->uniqueStrings($audiences, 8);
    }

    /**
     * @return array<int,string>
     */
    private function keyPoints(OpportunityExecutionPlan $plan, string $primaryKeyword): array
    {
        $evidence = collect((array) data_get($plan->source_evidence, 'signals', []))
            ->map(fn (array $signal): string => trim((string) ($signal['topic'] ?? $signal['entity'] ?? '')))
            ->filter()
            ->unique()
            ->take(3)
            ->values()
            ->all();

        $plannedRecommendations = collect((array) $plan->planned_steps)
            ->pluck('title')
            ->map(fn (mixed $step): string => trim((string) $step))
            ->filter()
            ->take(3)
            ->implode(', ');

        return array_values(array_filter([
            'Problem: Explain the visibility or demand gap behind '.$primaryKeyword.' and why the reader should act now.',
            'Evidence: Use the linked signal intelligence and opportunity scores to ground the argument'.($evidence !== [] ? ' around '.implode(', ', $evidence).'.' : '.'),
            'Explanation: Clarify the concepts, entities, and decision criteria in plain language.',
            'Recommendations: Translate the execution plan into practical next steps'.($plannedRecommendations !== '' ? ': '.$plannedRecommendations.'.' : '.'),
            'Comparison: Show how traditional SEO work differs from AI visibility, GEO, AEO, and LLM optimization.',
            'Actionable checklist: Provide a concise audit or implementation checklist readers can use immediately.',
            'Conclusion: Tie the insight back to measurable visibility improvement and the selected call to action.',
        ]));
    }

    /**
     * @param array<string,mixed> $sourceContext
     */
    private function notes(OpportunityExecutionPlan $plan, array $sourceContext): string
    {
        $lines = [
            'Created from Opportunity Execution Plan.',
            '',
            'Editorial brief enrichment: opportunity intelligence, execution plan, linked signal evidence, AI visibility context, and existing content links were used to prefill this brief.',
            'Primary keyword: '.(string) ($sourceContext['primary_keyword'] ?? ''),
            'Search intent: '.(string) data_get($sourceContext, 'editorial_brief.search_intent', ''),
            'Objective: '.(string) $plan->objective,
            'Recommended channel: '.(string) $plan->recommended_channel,
            'Recommended format: '.(string) $plan->recommended_format,
            'Opportunity ID: '.(string) $plan->opportunity_id,
            'Execution plan ID: '.(string) $plan->id,
        ];

        $detections = Arr::wrap($sourceContext['signal_detection_ids'] ?? []);
        if ($detections !== []) {
            $lines[] = 'Signal detections: '.implode(', ', $detections);
        }

        $events = Arr::wrap($sourceContext['signal_event_ids'] ?? []);
        if ($events !== []) {
            $lines[] = 'Signal events: '.implode(', ', $events);
        }

        $steps = $this->keyPoints($plan, (string) ($sourceContext['primary_keyword'] ?? $plan->opportunity?->topic ?? 'the topic'));
        if ($steps !== []) {
            $lines[] = '';
            $lines[] = 'Structured editorial outline:';
            foreach ($steps as $index => $step) {
                $lines[] = ($index + 1).'. '.$step;
            }
        }

        $questions = Arr::wrap(data_get($sourceContext, 'key_questions', []));
        if ($questions !== []) {
            $lines[] = '';
            $lines[] = 'Key questions:';
            foreach ($questions as $question) {
                $lines[] = '- '.$question;
            }
        }

        return implode("\n", $lines);
    }

    private function editorialTitle(OpportunityExecutionPlan $plan, string $primaryKeyword, string $searchIntent): string
    {
        $keyword = $this->titleCaseKeyword($primaryKeyword);
        $category = $this->category($plan);
        $text = $this->normalizedHaystack($plan, [$primaryKeyword]);

        $title = match (true) {
            str_contains($text, 'tool') && str_contains($text, 'ai') => 'Best '.$keyword.' for AI Search Visibility in 2026',
            $category === OpportunityCategory::AI_VISIBILITY_OPPORTUNITY->value,
                $category === OpportunityCategory::BRAND_VISIBILITY->value => 'How to Improve '.$keyword.' Beyond Traditional SEO',
            $searchIntent === 'comparison' => $keyword.': Comparison Guide for B2B Teams',
            $searchIntent === 'commercial' => 'Best '.$keyword.' Options for B2B Teams',
            default => $keyword.': Practical Guide for B2B Marketing Teams',
        };

        return $this->cleanTitle($title);
    }

    private function primaryKeyword(OpportunityExecutionPlan $plan): string
    {
        $candidates = collect([
            data_get($plan->opportunity?->metadata, 'primary_keyword'),
            data_get($plan->opportunity?->metadata, 'keyword'),
            data_get($plan->opportunity?->evidence, 'primary_keyword'),
            data_get($plan->source_evidence, 'primary_keyword'),
            $plan->opportunity?->topic,
            ...collect($plan->opportunity?->signals ?? [])->pluck('topic')->all(),
            $plan->opportunity?->title,
            $plan->title,
        ])
            ->flatten()
            ->map(fn (mixed $value): string => $this->keywordCandidate((string) $value))
            ->filter(fn (string $value): bool => $value !== '' && ! $this->isPlaceholder($value))
            ->values();

        $best = $candidates
            ->sortByDesc(fn (string $candidate): int => $this->keywordScore($candidate))
            ->first();

        return $best ?: 'AI visibility';
    }

    /**
     * @return array<int,string>
     */
    private function secondaryKeywords(OpportunityExecutionPlan $plan, string $primaryKeyword): array
    {
        $seed = [
            ...Arr::wrap(data_get($plan->opportunity?->metadata, 'secondary_keywords', [])),
            ...Arr::wrap(data_get($plan->opportunity?->metadata, 'keywords', [])),
            ...Arr::wrap(data_get($plan->opportunity?->evidence, 'keywords', [])),
            ...Arr::wrap(data_get($plan->source_evidence, 'keywords', [])),
            ...collect((array) data_get($plan->source_evidence, 'signals', []))->flatMap(fn (array $signal): array => [
                $signal['topic'] ?? null,
                $signal['entity'] ?? null,
                ...Arr::wrap(data_get($signal, 'evidence_summary.keywords', [])),
            ])->all(),
            ...collect($plan->opportunity?->signals ?? [])->flatMap(fn ($signal): array => [
                $signal->topic,
                $signal->entity,
                ...Arr::wrap(data_get($signal->metadata, 'keywords', [])),
            ])->all(),
        ];

        if ($this->isAiVisibilityContext($plan, [$primaryKeyword])) {
            $seed = array_merge($seed, [
                'AI search visibility',
                'Generative Engine Optimization',
                'GEO',
                'Answer Engine Optimization',
                'AEO',
                'AI Visibility',
                'Semantic SEO',
                'LLM Optimization',
                'Brand Visibility',
                'AI Search',
                'Content Optimization',
                'Entity SEO',
            ]);
        }

        return collect($seed)
            ->map(fn (mixed $value): string => $this->cleanKeyword((string) $value))
            ->filter(fn (string $value): bool => $value !== '' && ! $this->sameTerm($value, $primaryKeyword) && ! $this->isPlaceholder($value))
            ->unique(fn (string $value): string => Str::lower($value))
            ->sortByDesc(fn (string $value): int => $this->keywordScore($value))
            ->values()
            ->take(18)
            ->all();
    }

    private function searchIntent(OpportunityExecutionPlan $plan, string $primaryKeyword): string
    {
        $text = $this->normalizedHaystack($plan, [$primaryKeyword]);
        $format = (string) $plan->recommended_format;

        $intent = match (true) {
            preg_match('/\b(vs|versus|compare|alternatives?)\b/', $text) === 1 => 'comparison',
            preg_match('/\b(pricing|buy|purchase|sign up|request demo|book|trial)\b/', $text) === 1 => 'transactional',
            preg_match('/\b(best|top|tools?|software|platforms?|vendors?|solutions?)\b/', $text) === 1 => 'commercial',
            str_contains($format, 'comparison') => 'comparison',
            preg_match('/\b(login|support|dashboard|contact)\b/', $text) === 1 => 'navigational',
            default => 'informational',
        };

        return in_array($intent, self::SEARCH_INTENTS, true) ? $intent : 'informational';
    }

    private function funnelStage(string $searchIntent): string
    {
        return match ($searchIntent) {
            'commercial', 'comparison' => 'consideration',
            'transactional', 'navigational' => 'decision',
            default => 'awareness',
        };
    }

    private function toneOfVoice(string $searchIntent): string
    {
        return match ($searchIntent) {
            'commercial', 'comparison' => 'Authoritative, evidence-based, practical, vendor-neutral.',
            'transactional' => 'Confident, practical, specific, conversion-aware.',
            default => 'Authoritative, evidence-based, practical, clear.',
        };
    }

    private function uniqueAngle(OpportunityExecutionPlan $plan, string $primaryKeyword, string $searchIntent): string
    {
        if ($this->isAiVisibilityContext($plan, [$primaryKeyword])) {
            return 'Explain why traditional SEO alone is no longer sufficient for AI search visibility and how AI Visibility changes the optimization strategy.';
        }

        if ($searchIntent === 'comparison') {
            return 'Give readers a neutral comparison framework grounded in the linked opportunity evidence and clear next-step recommendations.';
        }

        return Str::limit((string) ($plan->objective ?: $plan->opportunity?->summary ?: $plan->summary), 500, '');
    }

    /**
     * @return array<int,string>
     */
    private function objectives(OpportunityExecutionPlan $plan, string $primaryKeyword): array
    {
        $objectives = [
            'Help readers understand why '.$primaryKeyword.' matters now.',
            'Explain the evidence behind the opportunity in practical terms.',
            'Show which concepts, tools, or workflows readers should evaluate.',
            'Give readers a clear checklist for improving visibility and citations.',
        ];

        if ($this->isAiVisibilityContext($plan, [$primaryKeyword])) {
            $objectives = [
                'Help readers understand why AI search differs from traditional Google search.',
                'Explain how AI Visibility works and why citations matter.',
                'Clarify which AI SEO tools, GEO practices, and AEO workflows matter.',
                'Show how Argusly helps teams monitor mentions, gaps, and content actions.',
            ];
        }

        return $objectives;
    }

    /**
     * @return array<int,string>
     */
    private function keyQuestions(OpportunityExecutionPlan $plan, string $primaryKeyword, array $entities): array
    {
        if ($this->isAiVisibilityContext($plan, [$primaryKeyword, ...$entities])) {
            return [
                'What is AI Search Visibility?',
                'How is GEO different from SEO?',
                'Which AI SEO tools exist?',
                'Why are brands missing from ChatGPT?',
                'How can companies improve AI citations?',
                'How does Argusly solve this?',
            ];
        }

        return [
            'What does '.$primaryKeyword.' mean for B2B marketing teams?',
            'Why is this opportunity important now?',
            'What evidence supports the recommendation?',
            'What should teams do first?',
            'How should success be measured?',
        ];
    }

    /**
     * @param array<int,string> $secondaryKeywords
     * @return array<int,string>
     */
    private function entities(OpportunityExecutionPlan $plan, string $primaryKeyword, array $secondaryKeywords): array
    {
        $entities = [
            ...Arr::wrap(data_get($plan->opportunity?->metadata, 'entities', [])),
            ...Arr::wrap(data_get($plan->opportunity?->evidence, 'entities', [])),
            ...collect($plan->opportunity?->signals ?? [])->pluck('entity')->all(),
            ...collect((array) data_get($plan->source_evidence, 'signals', []))->pluck('entity')->all(),
        ];

        if ($this->isAiVisibilityContext($plan, [$primaryKeyword, ...$secondaryKeywords])) {
            $entities = array_merge($entities, [
                'OpenAI',
                'ChatGPT',
                'Claude',
                'Gemini',
                'Google',
                'Bing',
                'Schema.org',
                'Knowledge Graph',
                'LLM',
                'AI Search',
                'Argusly',
            ]);
        }

        return $this->uniqueStrings($entities, 20);
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function recommendedInternalLinks(OpportunityExecutionPlan $plan, string $clientSiteId, string $primaryKeyword, array $secondaryKeywords, array $entities): array
    {
        $terms = $this->uniqueStrings([$primaryKeyword, ...$secondaryKeywords, ...$entities], 25);
        if ($terms === []) {
            return [];
        }

        $contents = Content::query()
            ->where('workspace_id', $plan->workspace_id)
            ->where('client_site_id', $clientSiteId)
            ->orderByDesc('updated_at')
            ->limit(80)
            ->get(['id', 'title', 'primary_keyword', 'type', 'publish_url_key', 'canonical_url_key', 'seo_canonical']);

        return $contents
            ->map(function (Content $content) use ($terms): ?array {
                $haystack = Str::lower(trim((string) $content->title.' '.(string) $content->primary_keyword.' '.(string) $content->type));
                $matched = collect($terms)
                    ->first(fn (string $term): bool => $term !== '' && str_contains($haystack, Str::lower($term)));

                if (! $matched) {
                    return null;
                }

                return [
                    'content_id' => (string) $content->id,
                    'title' => (string) $content->title,
                    'anchor_text' => (string) ($content->primary_keyword ?: $matched),
                    'reason' => 'Matches '.$matched.' in existing content taxonomy or keyword context.',
                    'url_key' => (string) ($content->canonical_url_key ?: $content->publish_url_key ?: $content->seo_canonical ?: ''),
                ];
            })
            ->filter()
            ->values()
            ->take(8)
            ->all();
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function externalReferences(string $primaryKeyword, array $entities): array
    {
        $references = [
            ['name' => 'Google Search Central', 'reason' => 'Search quality, structured data, and SEO guidance.'],
            ['name' => 'OpenAI', 'reason' => 'AI search, model behavior, and ChatGPT ecosystem context.'],
            ['name' => 'Anthropic', 'reason' => 'LLM assistant behavior and AI answer context.'],
            ['name' => 'Microsoft Bing', 'reason' => 'AI search and answer engine ecosystem context.'],
            ['name' => 'Schema.org', 'reason' => 'Structured data vocabulary recommendations.'],
            ['name' => 'Academic research', 'reason' => 'Use peer-reviewed or institution-backed research for claims.'],
        ];

        if (collect([$primaryKeyword, ...$entities])->contains(fn (string $value): bool => str_contains(Str::lower($value), 'cloudflare'))) {
            $references[] = ['name' => 'Cloudflare', 'reason' => 'Crawler, AI bot, and web infrastructure context.'];
        }

        return $references;
    }

    /**
     * @return array<int,string>
     */
    private function schemaRecommendations(string $contentType, string $searchIntent, array $keyQuestions): array
    {
        $schema = ['Article', 'Breadcrumb', 'Organization'];

        if ($keyQuestions !== []) {
            $schema[] = 'FAQ';
        }

        if ($searchIntent === 'commercial' || $searchIntent === 'comparison') {
            $schema[] = 'SoftwareApplication';
        }

        if (in_array($contentType, ['howto', 'guide'], true)) {
            $schema[] = 'HowTo';
        }

        return $this->uniqueStrings($schema, 8);
    }

    /**
     * @return array<int,string>
     */
    private function faqSuggestions(array $keyQuestions, string $primaryKeyword, array $entities): array
    {
        $faq = $keyQuestions;
        $faq[] = 'What is the fastest way to improve '.$primaryKeyword.'?';
        $faq[] = 'Which metrics show whether '.$primaryKeyword.' is improving?';
        $faq[] = 'How often should teams review AI visibility and citation coverage?';
        $faq[] = 'What role do entities and structured data play?';
        $faq[] = 'When should a company request an AI Visibility Audit?';

        if (collect($entities)->contains(fn (string $entity): bool => Str::lower($entity) === 'argusly')) {
            $faq[] = 'How does Argusly monitor brand visibility in AI search?';
        }

        return $this->uniqueStrings($faq, 12);
    }

    private function callToAction(OpportunityExecutionPlan $plan, string $searchIntent): string
    {
        if ($this->isAiVisibilityContext($plan, [])) {
            return match ($searchIntent) {
                'transactional', 'commercial', 'comparison' => 'Book an AI Visibility Audit',
                default => 'Start monitoring AI mentions',
            };
        }

        return match ($searchIntent) {
            'transactional', 'commercial', 'comparison' => 'Request a demo',
            default => 'Analyse your brand visibility',
        };
    }

    /**
     * @return array<int,string>
     */
    private function distributionSuggestions(string $searchIntent): array
    {
        $suggestions = ['Website', 'LinkedIn', 'Newsletter', 'Repurpose into short social posts'];

        if (in_array($searchIntent, ['commercial', 'comparison'], true)) {
            $suggestions[] = 'Comparison article';
        }

        $suggestions[] = 'Follow-up guide';

        return $suggestions;
    }

    /**
     * @return array<int,string>
     */
    private function successMetrics(OpportunityExecutionPlan $plan): array
    {
        $metrics = ['Organic traffic', 'Engagement', 'Conversions'];

        if ($this->isAiVisibilityContext($plan, [])) {
            $metrics = ['Organic traffic', 'AI citations', 'Brand mentions', 'Internal links', 'Engagement', 'Conversions'];
        }

        return $metrics;
    }

    /**
     * @return array<int,string>
     */
    private function humanizationNotes(): array
    {
        return [
            'Avoid AI clichés and generic setup paragraphs.',
            'Use concrete examples from the opportunity and signal evidence.',
            'Include original interpretation rather than repeating common definitions.',
            'Use short paragraphs and practical transitions.',
            'Support claims with evidence or named source context.',
            'Avoid generic conclusions; close with a specific next step.',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function qualityGates(): array
    {
        return [
            'Primary keyword must not be empty.',
            'No placeholder values.',
            'No duplicated keywords or entities.',
            'No "Execution plan:" title prefix.',
            'CTA must be context-aware.',
            'Outline must include problem, evidence, explanation, recommendations, comparison, checklist, and conclusion.',
        ];
    }

    /**
     * @param array<int,string> $secondaryKeywords
     * @param array<int,string> $entities
     * @param array<int,string> $keyPoints
     */
    private function assertQualityGates(
        string $title,
        string $primaryKeyword,
        array $secondaryKeywords,
        array $entities,
        array $keyPoints,
        string $cta,
    ): void {
        $errors = [];

        if (trim($primaryKeyword) === '' || $this->isPlaceholder($primaryKeyword)) {
            $errors[] = 'primary_keyword';
        }

        if (preg_match('/^execution plan:/i', trim($title)) === 1 || $this->isPlaceholder($title)) {
            $errors[] = 'title';
        }

        if ($this->hasDuplicates($secondaryKeywords)) {
            $errors[] = 'secondary_keywords';
        }

        if ($this->hasDuplicates($entities)) {
            $errors[] = 'entities';
        }

        if ($this->isGenericCta($cta)) {
            $errors[] = 'call_to_action';
        }

        foreach (['Problem:', 'Evidence:', 'Explanation:', 'Recommendations:', 'Comparison:', 'Actionable checklist:', 'Conclusion:'] as $requiredSection) {
            if (! collect($keyPoints)->contains(fn (string $point): bool => str_starts_with($point, $requiredSection))) {
                $errors[] = 'key_points';

                break;
            }
        }

        if ($errors !== []) {
            throw new RuntimeException('Opportunity execution plan brief enrichment failed quality gates: '.implode(', ', array_unique($errors)));
        }
    }

    private function category(OpportunityExecutionPlan $plan): string
    {
        return (string) ($plan->opportunity?->category?->value ?? $plan->opportunity?->category ?? '');
    }

    private function isAiVisibilityContext(OpportunityExecutionPlan $plan, array $terms): bool
    {
        $category = $this->category($plan);

        return in_array($category, [OpportunityCategory::AI_VISIBILITY_OPPORTUNITY->value, OpportunityCategory::BRAND_VISIBILITY->value], true)
            || str_contains($this->normalizedHaystack($plan, $terms), 'ai visibility')
            || str_contains($this->normalizedHaystack($plan, $terms), 'ai search')
            || str_contains($this->normalizedHaystack($plan, $terms), 'chatgpt')
            || str_contains($this->normalizedHaystack($plan, $terms), 'llm');
    }

    private function normalizedHaystack(OpportunityExecutionPlan $plan, array $extra = []): string
    {
        return Str::lower(implode(' ', array_filter([
            ...$extra,
            $plan->title,
            $plan->summary,
            $plan->objective,
            $plan->recommended_format,
            $plan->opportunity?->title,
            $plan->opportunity?->topic,
            $plan->opportunity?->summary,
            json_encode($plan->planned_steps),
            json_encode($plan->source_evidence),
            json_encode($plan->opportunity?->metadata),
            json_encode($plan->opportunity?->evidence),
            json_encode($plan->opportunity?->source_signal_summary),
        ])));
    }

    private function keywordCandidate(string $value): string
    {
        $value = $this->cleanKeyword($value);
        $value = preg_replace('/^(execution plan|content gap|trend opportunity|ai visibility opportunity|brand visibility|competitor movement)\s*:\s*/i', '', $value) ?? $value;
        $value = preg_replace('/^(create|write|publish|build|improve|capture|respond to)\s+(an?\s+)?/i', '', $value) ?? $value;
        $value = preg_replace('/\s+(guide|brief|article|post|page)$/i', '', $value) ?? $value;

        return $this->cleanKeyword($value);
    }

    private function cleanKeyword(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? $value);
        $value = trim($value, " \t\n\r\0\x0B.,;:|");

        return Str::limit($value, 80, '');
    }

    private function titleCaseKeyword(string $keyword): string
    {
        $smallWords = ['ai', 'seo', 'geo', 'aeo', 'llm'];
        $words = preg_split('/\s+/', $keyword) ?: [];

        return collect($words)
            ->map(function (string $word) use ($smallWords): string {
                $lower = Str::lower($word);

                return in_array($lower, $smallWords, true) ? Str::upper($lower) : Str::ucfirst($lower);
            })
            ->implode(' ');
    }

    private function cleanTitle(string $title): string
    {
        $title = preg_replace('/^Execution plan:\s*/i', '', $title) ?? $title;

        return Str::limit(trim($title), 180, '');
    }

    private function keywordScore(string $candidate): int
    {
        $words = preg_split('/\s+/', trim($candidate)) ?: [];
        $wordCount = count(array_filter($words));
        $lower = Str::lower($candidate);

        $score = 0;
        $score += $wordCount >= 2 && $wordCount <= 5 ? 30 : 0;
        $score += str_contains($lower, 'tool') ? 14 : 0;
        $score += str_contains($lower, 'ai') ? 12 : 0;
        $score += str_contains($lower, 'seo') ? 10 : 0;
        $score += str_contains($lower, 'visibility') ? 9 : 0;
        $score += mb_strlen($candidate) <= 60 ? 4 : -8;

        return $score;
    }

    private function isPlaceholder(string $value): bool
    {
        $lower = Str::lower(trim($value));

        return $lower === ''
            || str_contains($lower, 'execution plan:')
            || in_array($lower, ['opportunity execution brief', 'this opportunity', 'untitled'], true);
    }

    private function sameTerm(string $a, string $b): bool
    {
        return Str::lower(trim($a)) === Str::lower(trim($b));
    }

    /**
     * @param array<int,string> $values
     */
    private function hasDuplicates(array $values): bool
    {
        $normalized = collect($values)
            ->map(fn (string $value): string => Str::lower(trim($value)))
            ->filter()
            ->values();

        return $normalized->count() !== $normalized->unique()->count();
    }

    private function isGenericCta(string $cta): bool
    {
        return in_array(Str::lower(trim($cta)), [
            'learn more',
            'read more',
            'contact us',
            'turn this opportunity into a concrete content asset.',
        ], true);
    }

    /**
     * @param array<int,mixed> $values
     * @return array<int,string>
     */
    private function uniqueStrings(array $values, int $limit): array
    {
        return collect($values)
            ->flatten()
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '' && ! $this->isPlaceholder($value))
            ->unique(fn (string $value): string => Str::lower($value))
            ->values()
            ->take($limit)
            ->all();
    }
}
