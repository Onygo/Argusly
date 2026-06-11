<?php

namespace App\Services\Growth;

use App\Enums\OpportunityCategory;
use App\Enums\ProgrammaticPatternType;
use App\Models\AgenticMarketingOpportunity;
use App\Models\ContentOpportunity;
use App\Models\CompetitorContentOpportunity;
use App\Models\Opportunity;
use App\Models\OpportunitySignal;
use App\Models\ProgrammaticOpportunity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class ProgrammaticOpportunityDetector
{
    public function detect(Model $source): ?ProgrammaticOpportunity
    {
        $workspaceId = $this->workspaceIdFor($source);
        if ($workspaceId === '') {
            throw new RuntimeException('Programmatic opportunity source is missing a workspace.');
        }

        $text = $this->sourceText($source);
        $pattern = $this->detectPattern($text, $source);
        if (! $pattern) {
            return null;
        }

        $baseTopic = $this->baseTopic($source, $text);
        $scores = $this->scores($source, $pattern, $text);
        $variables = $this->exampleVariables($pattern, $text);

        return ProgrammaticOpportunity::query()->updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'source_type' => $source->getMorphClass(),
                'source_id' => (string) $source->getKey(),
                'pattern_type' => $pattern->value,
                'base_topic' => $baseTopic,
            ],
            [
                'organization_id' => $this->organizationIdFor($source),
                'pattern_type' => $pattern->value,
                'variable_axis' => $this->variableAxis($pattern),
                'example_variables' => $variables,
                'estimated_variants_count' => $this->estimatedVariants($pattern, $variables),
                'scale_score' => $scores['scale_score'],
                'business_value_score' => $scores['business_value_score'],
                'seo_opportunity_score' => $scores['seo_opportunity_score'],
                'ai_visibility_score' => $scores['ai_visibility_score'],
                'competition_score' => $scores['competition_score'],
                'confidence_score' => $scores['confidence_score'],
                'status' => ProgrammaticOpportunity::STATUS_DETECTED,
                'explanation' => [
                    'pattern' => $pattern->description(),
                    'signals' => $scores['signals'],
                    'source_summary' => Str::limit($text, 500, ''),
                ],
                'metadata' => [
                    'source_class' => $source::class,
                    'source_title' => (string) ($source->title ?? $source->topic ?? ''),
                ],
                'detected_at' => now(),
            ]
        )->refresh();
    }

    private function detectPattern(string $text, Model $source): ?ProgrammaticPatternType
    {
        $lower = Str::lower($text);

        if (preg_match('/\b(vs|versus|compare|comparison|vergelijk)\b/i', $text)) {
            return ProgrammaticPatternType::COMPARISON_PAGE;
        }
        if (preg_match('/\b(alternative|alternatief|alternatives|replacement|replace)\b/i', $text)) {
            return ProgrammaticPatternType::ALTERNATIVE_PAGE;
        }
        if (preg_match('/\b(faq|frequently asked|veelgestelde vragen|questions about|vragen over)\b/i', $text)) {
            return ProgrammaticPatternType::FAQ_LIBRARY;
        }
        if (preg_match('/\b(ai answer|answer library|antwoordbibliotheek|conversational|llm|ai visibility|ai citation)\b/i', $text)) {
            return ProgrammaticPatternType::AI_ANSWER_LIBRARY;
        }
        if (preg_match('/\b(integration|integratie|connector|connects with|works with)\b/i', $text)) {
            return ProgrammaticPatternType::INTEGRATION_PAGE;
        }
        if (preg_match('/\b(feature|functie|capability|module)\b/i', $text)) {
            return ProgrammaticPatternType::FEATURE_PAGE;
        }
        if (preg_match('/\b(best|beste)\b.+\b(for|voor)\b/i', $text) || preg_match('/\b(for|voor)\b.+\b(use case|workflow|team|rol|role)\b/i', $text)) {
            return ProgrammaticPatternType::USE_CASE_PAGE;
        }
        if (preg_match('/\b(for|voor)\b.+\b(industry|industrie|sector|markt|branche)\b/i', $text)) {
            return ProgrammaticPatternType::INDUSTRY_PAGE;
        }
        if (preg_match('/\b(in|near|voor|location|locatie|stad|city|regio|region)\b/i', $text) && preg_match('/\b(service|dienst|agency|bureau|consultant)\b/i', $text)) {
            return ProgrammaticPatternType::LOCATION_PAGE;
        }

        if ($source instanceof CompetitorContentOpportunity) {
            return ProgrammaticPatternType::COMPARISON_PAGE;
        }
        if ($source instanceof ContentOpportunity && str_contains($lower, 'schema')) {
            return ProgrammaticPatternType::FEATURE_PAGE;
        }
        if ($source instanceof OpportunitySignal && str_contains($lower, 'question')) {
            return ProgrammaticPatternType::FAQ_LIBRARY;
        }

        return null;
    }

    private function sourceText(Model $source): string
    {
        $parts = [
            $source->title ?? null,
            $source->topic ?? null,
            $source->summary ?? null,
            $source->reasoning ?? null,
            $source->reason ?? null,
            $source->attackable_angle ?? null,
            $source->ai_visibility_opportunity ?? null,
            $source->query_intent ?? null,
            $source->primary_search_intent ?? null,
            $source->entity ?? null,
            json_encode($source->metadata ?? $source->payload ?? $source->source_signals ?? []),
        ];

        return trim(collect($parts)->filter()->implode(' '));
    }

    private function baseTopic(Model $source, string $text): string
    {
        $candidate = trim((string) ($source->topic ?? $source->title ?? $source->entity ?? ''));
        $candidate = preg_replace('/\b(vs|versus|alternative|alternatief|faq|veelgestelde vragen|best|beste)\b/i', '', $candidate) ?: $candidate;
        $candidate = trim(preg_replace('/\s+/', ' ', (string) $candidate));

        return Str::limit($candidate !== '' ? $candidate : Str::words($text, 8, ''), 180, '');
    }

    private function variableAxis(ProgrammaticPatternType $pattern): string
    {
        return match ($pattern) {
            ProgrammaticPatternType::INDUSTRY_PAGE => 'industry',
            ProgrammaticPatternType::LOCATION_PAGE => 'location',
            ProgrammaticPatternType::COMPARISON_PAGE => 'competitor_or_product',
            ProgrammaticPatternType::ALTERNATIVE_PAGE => 'alternative',
            ProgrammaticPatternType::USE_CASE_PAGE => 'use_case',
            ProgrammaticPatternType::FAQ_LIBRARY => 'question',
            ProgrammaticPatternType::AI_ANSWER_LIBRARY => 'answer_question',
            ProgrammaticPatternType::FEATURE_PAGE => 'feature',
            ProgrammaticPatternType::INTEGRATION_PAGE => 'integration',
        };
    }

    /**
     * @return array<int,string>
     */
    private function exampleVariables(ProgrammaticPatternType $pattern, string $text): array
    {
        return match ($pattern) {
            ProgrammaticPatternType::INDUSTRY_PAGE => ['SaaS', 'healthcare', 'finance', 'manufacturing'],
            ProgrammaticPatternType::LOCATION_PAGE => ['Amsterdam', 'Rotterdam', 'Utrecht', 'Eindhoven'],
            ProgrammaticPatternType::COMPARISON_PAGE => $this->extractComparedTerms($text) ?: ['Product A', 'Product B', 'Platform category'],
            ProgrammaticPatternType::ALTERNATIVE_PAGE => ['cheaper alternative', 'enterprise alternative', 'open source alternative'],
            ProgrammaticPatternType::USE_CASE_PAGE => ['marketing teams', 'support teams', 'compliance workflow'],
            ProgrammaticPatternType::FAQ_LIBRARY => ['what is it', 'how does it work', 'what does it cost'],
            ProgrammaticPatternType::AI_ANSWER_LIBRARY => ['definition', 'comparison answer', 'buying criteria'],
            ProgrammaticPatternType::FEATURE_PAGE => ['reporting', 'automation', 'governance', 'integrations'],
            ProgrammaticPatternType::INTEGRATION_PAGE => ['HubSpot', 'Salesforce', 'WordPress', 'Slack'],
        };
    }

    /**
     * @return array<int,string>
     */
    private function extractComparedTerms(string $text): array
    {
        if (preg_match('/([A-Za-z0-9][A-Za-z0-9 \-]{1,40})\s+(?:vs|versus)\s+([A-Za-z0-9][A-Za-z0-9 \-]{1,40})/i', $text, $matches)) {
            return [trim($matches[1]), trim($matches[2])];
        }

        return [];
    }

    private function estimatedVariants(ProgrammaticPatternType $pattern, array $variables): int
    {
        $base = match ($pattern) {
            ProgrammaticPatternType::INDUSTRY_PAGE, ProgrammaticPatternType::LOCATION_PAGE => 40,
            ProgrammaticPatternType::FAQ_LIBRARY, ProgrammaticPatternType::AI_ANSWER_LIBRARY => 30,
            ProgrammaticPatternType::COMPARISON_PAGE, ProgrammaticPatternType::ALTERNATIVE_PAGE => 20,
            ProgrammaticPatternType::USE_CASE_PAGE, ProgrammaticPatternType::FEATURE_PAGE, ProgrammaticPatternType::INTEGRATION_PAGE => 15,
        };

        return max($base, count($variables) * 5);
    }

    /**
     * @return array<string,mixed>
     */
    private function scores(Model $source, ProgrammaticPatternType $pattern, string $text): array
    {
        $variants = $this->estimatedVariants($pattern, $this->exampleVariables($pattern, $text));
        $business = $this->scoreFromFirstAvailable($source, ['impact_score', 'business_value_score', 'priority_score', 'expected_impact']);
        $seo = $this->seoScore($source, $pattern);
        $ai = $this->aiScore($source, $pattern, $text);
        $competition = $source instanceof CompetitorContentOpportunity ? $this->scoreFromFirstAvailable($source, ['impact_score', 'priority_score']) : null;
        $confidence = $this->confidenceScore($pattern, $text);

        return [
            'scale_score' => $this->clamp($variants * 2.5),
            'business_value_score' => $business,
            'seo_opportunity_score' => $seo,
            'ai_visibility_score' => $ai,
            'competition_score' => $competition,
            'confidence_score' => $confidence,
            'signals' => array_values(array_filter([
                'variants' => $variants,
                $business !== null ? 'business_score_from_source' : null,
                $seo !== null ? 'seo_intent_detected' : null,
                $ai !== null ? 'ai_visibility_intent_detected' : null,
                $competition !== null ? 'competitor_gap_source' : null,
            ])),
        ];
    }

    private function seoScore(Model $source, ProgrammaticPatternType $pattern): ?float
    {
        if ($source instanceof Opportunity && (string) ($source->category?->value ?? $source->category) === OpportunityCategory::CONTENT_GAP->value) {
            return $this->clamp((float) ($source->priority_score ?: 60));
        }
        if ($source instanceof ContentOpportunity || $source instanceof CompetitorContentOpportunity) {
            return $this->clamp((float) ($source->priority_score ?: 60));
        }

        return in_array($pattern, [
            ProgrammaticPatternType::COMPARISON_PAGE,
            ProgrammaticPatternType::ALTERNATIVE_PAGE,
            ProgrammaticPatternType::USE_CASE_PAGE,
            ProgrammaticPatternType::FAQ_LIBRARY,
        ], true) ? 55.0 : null;
    }

    private function aiScore(Model $source, ProgrammaticPatternType $pattern, string $text): ?float
    {
        if (in_array($pattern, [ProgrammaticPatternType::AI_ANSWER_LIBRARY, ProgrammaticPatternType::FAQ_LIBRARY], true)) {
            return $this->clamp($this->scoreFromFirstAvailable($source, ['impact_score', 'priority_score']) ?? 70);
        }

        return preg_match('/\b(ai|llm|answer|citation|visibility)\b/i', $text)
            ? $this->clamp($this->scoreFromFirstAvailable($source, ['impact_score', 'priority_score']) ?? 62)
            : null;
    }

    private function confidenceScore(ProgrammaticPatternType $pattern, string $text): float
    {
        $score = match ($pattern) {
            ProgrammaticPatternType::COMPARISON_PAGE, ProgrammaticPatternType::ALTERNATIVE_PAGE, ProgrammaticPatternType::FAQ_LIBRARY => 82,
            ProgrammaticPatternType::AI_ANSWER_LIBRARY, ProgrammaticPatternType::INDUSTRY_PAGE, ProgrammaticPatternType::LOCATION_PAGE => 74,
            default => 66,
        };

        return $this->clamp($score + (str_word_count($text) > 8 ? 6 : 0));
    }

    /**
     * @param array<int,string> $fields
     */
    private function scoreFromFirstAvailable(Model $source, array $fields): ?float
    {
        foreach ($fields as $field) {
            $value = $source->getAttribute($field);
            if ($value !== null && is_numeric($value)) {
                return $this->clamp((float) $value);
            }
        }

        return null;
    }

    private function workspaceIdFor(Model $source): string
    {
        if ($source->getAttribute('workspace_id')) {
            return (string) $source->getAttribute('workspace_id');
        }
        if ($source instanceof AgenticMarketingOpportunity) {
            $source->loadMissing(['content', 'objective']);

            return (string) ($source->content?->workspace_id ?? $source->objective?->workspace_id ?? '');
        }

        return '';
    }

    private function organizationIdFor(Model $source): ?int
    {
        if ($source->getAttribute('organization_id')) {
            return (int) $source->getAttribute('organization_id');
        }
        if ($source instanceof AgenticMarketingOpportunity) {
            $source->loadMissing('objective');

            return $source->objective?->organization_id ? (int) $source->objective->organization_id : null;
        }

        return null;
    }

    private function clamp(float $score): float
    {
        return round(max(0, min(100, $score)), 2);
    }
}
