<?php

namespace App\Agents\Drafts;

use App\Agents\Contracts\AgentInterface;
use App\Agents\Data\AgentContext;
use App\Agents\Data\AgentResult;
use App\Models\Draft;
use Illuminate\Support\Str;

class DraftSmartSuggestionsAgent implements AgentInterface
{
    public const KEY = 'draft.smart_suggestions';

    public function key(): string
    {
        return self::KEY;
    }

    public function supports(AgentContext $context): bool
    {
        return $context->draftId !== null;
    }

    public function run(AgentContext $context): AgentResult
    {
        $draft = Draft::query()
            ->with([
                'analysis',
                'clientSite.workspace',
                'content',
            ])
            ->findOrFail($context->draftId);

        $wordCount = str_word_count(trim(strip_tags((string) $draft->content_html)));
        $hasAnalysis = $draft->analysis !== null;
        $analysisPayload = $draft->analysis?->canonicalPayload() ?? [];
        $analysisSummary = trim((string) data_get($analysisPayload, 'summary.headline', ''));
        $ctaExplanation = trim((string) data_get($analysisPayload, 'sections.cta.explanation', ''));

        $suggestions = [];
        $actions = [];
        $warnings = [];

        if (! $hasAnalysis) {
            $suggestions[] = [
                'title' => 'Suggested improvements',
                'description' => 'Run draft intelligence to score structure, SEO, readability, and conversion fit before revising this draft.',
            ];
        } elseif ($analysisSummary !== '') {
            $suggestions[] = [
                'title' => 'Suggested improvements',
                'description' => $analysisSummary,
            ];
        }

        if ($wordCount < 250) {
            $suggestions[] = [
                'title' => 'Expand the core draft',
                'description' => 'This draft is still short for a publishable editorial pass. Add proof points, examples, or a stronger conclusion.',
            ];
        }

        if (trim((string) $draft->seo_meta_description) === '') {
            $suggestions[] = [
                'title' => 'Tighten the search snippet',
                'description' => 'Add a meta description so the draft has a clear search-facing summary before delivery.',
            ];
        }

        if ($ctaExplanation !== '') {
            $actions[] = [
                'title' => 'Review conversion guidance',
                'description' => $ctaExplanation,
            ];
        } else {
            $actions[] = [
                'title' => 'Review CTA placement',
                'description' => 'Check whether the close and one mid-article section make the next step explicit for the reader.',
            ];
        }

        if ($draft->content_id) {
            $actions[] = [
                'title' => 'Open the content workspace',
                'description' => 'Use the linked content item to persist revisions after reviewing this draft.',
                'href' => route('app.content.show', ['content' => $draft->content_id, 'tab' => 'draft']),
            ];
        }

        if ($draft->isTranslation()) {
            $warnings[] = [
                'title' => 'Translation review required',
                'description' => 'Keep locale-specific phrasing, CTA wording, and any regulated terminology aligned with the source locale before publishing.',
            ];
        }

        if ($wordCount === 0) {
            $warnings[] = [
                'title' => 'No draft body detected',
                'description' => 'This run could only inspect metadata because the draft body is empty.',
            ];
        }

        $locale = strtoupper((string) ($draft->language?->value ?? $draft->language ?? 'N/A'));
        $summary = sprintf(
            'Smart suggestions prepared for the %s draft with %d words reviewed.',
            $locale,
            $wordCount
        );

        return AgentResult::success(
            agentKey: self::KEY,
            summary: $summary,
            suggestions: $suggestions,
            actions: $actions,
            warnings: $warnings,
            metrics: [
                'word_count' => $wordCount,
                'has_analysis' => $hasAnalysis,
                'draft_status' => (string) $draft->status,
                'language' => (string) ($draft->language?->value ?? $draft->language),
            ],
            rawPayload: [
                'draft_title' => $draft->title,
                'content_excerpt' => Str::limit(trim(strip_tags((string) $draft->content_html)), 180),
                'analysis_status' => $draft->analysis?->effective_status ?? null,
            ],
        );
    }
}
