<?php

namespace App\Services\Drafts\Intelligence;

use App\Models\Draft;
use App\Services\LinkIntelligence\DefaultLinkSuggestionService;
use Throwable;

class DraftIntelligenceScanService
{
    public function __construct(
        private readonly DraftContentSnapshotBuilder $snapshotBuilder,
        private readonly DraftSignalExtractor $signalExtractor,
        private readonly DraftMetricScorer $metricScorer,
        private readonly DraftIntelligenceRubricRegistry $rubrics,
        private readonly DefaultLinkSuggestionService $linkSuggestionService,
    ) {}

    public function freshDraft(Draft $draft): Draft
    {
        return $this->snapshotBuilder->freshDraft($draft);
    }

    /**
     * @return array{draft: Draft, snapshot: array<string,mixed>, signals: array<string,mixed>, payload: array<string,mixed>}
     */
    public function buildDeterministicBaseline(Draft $draft): array
    {
        $freshDraft = $this->freshDraft($draft);
        $snapshot = $this->snapshotBuilder->build($freshDraft);
        $signals = $this->signalExtractor->extract($freshDraft, $snapshot);
        $payload = $this->metricScorer->score($snapshot, $signals);

        $payload['internal_link_opportunities'] = $this->internalLinkCandidates($freshDraft);
        $payload['internal_link_summary'] = $payload['internal_link_opportunities'] === []
            ? 'No strong internal link opportunities were identified from the current candidate set.'
            : 'Internal link opportunities were derived from related draft candidates in the same workspace.';
        $payload['context'] = [
            'snapshot_signature' => (string) ($snapshot['snapshot_signature'] ?? ''),
            'draft_updated_at' => $freshDraft->updated_at?->toIso8601String(),
            'rubric_version' => DraftIntelligenceRubricRegistry::VERSION,
            'source' => 'deterministic_baseline',
            'cta_score_band' => (string) data_get($payload, 'sections.cta.band_label', ''),
            'llm_visibility_score_band' => (string) data_get($payload, 'sections.llm_visibility.band_label', ''),
            'publish_readiness_status' => (string) data_get($payload, 'sections.publish_readiness.status_label', ''),
            'cta_excerpt' => data_get($signals, 'cta.cta_excerpt'),
            'cta_funnel_stage' => (string) ($freshDraft->brief?->funnel_stage ?? 'consideration'),
            'signals' => $signals,
        ];

        return [
            'draft' => $freshDraft,
            'snapshot' => $snapshot,
            'signals' => $signals,
            'payload' => $payload,
        ];
    }

    /**
     * @param array<string,mixed> $baselinePayload
     * @param array<string,mixed> $normalizedLlmPayload
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $signals
     * @return array<string,mixed>
     */
    public function mergeWithLlm(array $baselinePayload, array $normalizedLlmPayload, array $snapshot, array $signals): array
    {
        $scored = $this->metricScorer->score($snapshot, $signals, (array) data_get($normalizedLlmPayload, 'sections', []));
        $merged = array_merge($baselinePayload, $scored);

        $llmSummary = (array) data_get($normalizedLlmPayload, 'summary', []);
        $llmTopImprovements = collect((array) data_get($normalizedLlmPayload, 'top_improvements', []))
            ->filter(fn (mixed $item): bool => trim((string) $item) !== '')
            ->take(3)
            ->values()
            ->all();
        $llmLinks = collect((array) data_get($normalizedLlmPayload, 'internal_link_opportunities', []))
            ->filter(fn (array $item): bool => trim((string) ($item['target_title'] ?? '')) !== '')
            ->take(3)
            ->values()
            ->all();

        if (trim((string) ($llmSummary['headline'] ?? '')) !== '') {
            $merged['summary']['headline'] = (string) $llmSummary['headline'];
        }

        if (trim((string) ($llmSummary['overall_explanation'] ?? '')) !== '') {
            $merged['summary']['overall_explanation'] = (string) $llmSummary['overall_explanation'];
        }

        if ($llmTopImprovements !== []) {
            $merged['top_improvements'] = $llmTopImprovements;
        }

        if ($llmLinks !== []) {
            $merged['internal_link_opportunities'] = $llmLinks;
            $merged['internal_link_summary'] = trim((string) data_get($normalizedLlmPayload, 'internal_link_summary', ''))
                ?: 'Internal link opportunities were calibrated with the language model.';
        }

        $merged['context'] = array_merge((array) ($merged['context'] ?? []), [
            'source' => 'deterministic_plus_llm',
            'rubric_version' => DraftIntelligenceRubricRegistry::VERSION,
            'cta_score_band' => (string) data_get($merged, 'sections.cta.band_label', ''),
            'llm_visibility_score_band' => (string) data_get($merged, 'sections.llm_visibility.band_label', ''),
            'publish_readiness_status' => (string) data_get($merged, 'sections.publish_readiness.status_label', ''),
            'cta_excerpt' => data_get($signals, 'cta.cta_excerpt'),
            'cta_funnel_stage' => (string) data_get($merged, 'context.cta_funnel_stage', 'consideration'),
            'signals' => $signals,
        ]);

        return $merged;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $signals
     * @return array<string,mixed>
     */
    public function analysisPromptPayload(Draft $draft, array $snapshot, array $signals): array
    {
        return [
            'brief' => [
                'title' => $draft->brief?->title,
                'primary_keyword' => $draft->brief?->primary_keyword,
                'secondary_keywords' => (array) ($draft->brief?->secondary_keywords ?? []),
                'call_to_action' => $draft->brief?->call_to_action,
                'target_audience' => $draft->brief?->target_audience ?: $draft->brief?->audience,
                'tone_of_voice' => $draft->brief?->tone_of_voice,
                'funnel_stage' => $draft->brief?->funnel_stage,
                'content_type' => $draft->brief?->content_type,
            ],
            'brand_context' => [
                'brand_voice' => (array) ($snapshot['brand_voice'] ?? []),
                'company_profile' => (array) ($snapshot['company_profile'] ?? []),
            ],
            'draft' => [
                'title' => $snapshot['title'] ?? '',
                'seo_title' => $snapshot['seo_title'] ?? '',
                'seo_meta_description' => $snapshot['seo_meta_description'] ?? '',
                'seo_h1' => $snapshot['seo_h1'] ?? '',
                'plain_text' => $this->limitText((string) ($snapshot['plain_text'] ?? ''), 9000),
                'plain_text_excerpt' => $this->limitText((string) ($snapshot['plain_text'] ?? ''), 9000),
                'closing_plain_text' => $this->limitTail((string) ($snapshot['plain_text'] ?? ''), 2200),
                'cta_focus_excerpt' => implode("\n\n", array_slice((array) ($snapshot['cta_candidate_blocks'] ?? []), -3)),
                'headings' => (array) ($snapshot['headings'] ?? []),
                'intro' => (string) ($snapshot['intro'] ?? ''),
                'conclusion' => (string) ($snapshot['conclusion'] ?? ''),
                'sections' => (array) ($snapshot['sections'] ?? []),
            ],
            'deterministic_signals' => $signals,
            'rubrics' => $this->rubrics->all(),
            'internal_link_candidates' => $this->internalLinkCandidates($draft),
        ];
    }

    private function limitText(string $plainText, int $maxCharacters): string
    {
        if (mb_strlen($plainText) <= $maxCharacters) {
            return trim($plainText);
        }

        $headCharacters = (int) floor($maxCharacters * 0.62);
        $tailCharacters = max(1200, $maxCharacters - $headCharacters - 48);

        return trim(mb_substr($plainText, 0, $headCharacters) . "\n\n[...middle omitted for brevity...]\n\n" . mb_substr($plainText, -1 * $tailCharacters));
    }

    private function limitTail(string $plainText, int $maxCharacters): string
    {
        if (mb_strlen($plainText) <= $maxCharacters) {
            return trim($plainText);
        }

        return trim(mb_substr($plainText, -1 * $maxCharacters));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function internalLinkCandidates(Draft $draft): array
    {
        try {
            return $this->linkSuggestionService
                ->debugCandidates($draft)
                ->take(3)
                ->map(function (array $item): array {
                    $reasons = collect((array) ($item['reasons'] ?? []))
                        ->map(fn (mixed $reason): string => trim((string) $reason))
                        ->filter()
                        ->values();
                    $sharedEntities = collect((array) ($item['shared_entities'] ?? []))
                        ->map(fn (mixed $entity): string => trim((string) $entity))
                        ->filter()
                        ->values();

                    return [
                        'target_title' => (string) ($item['target_title'] ?? 'Untitled target'),
                        'reason' => (string) ($reasons->first() ?: 'Suggested from related draft context in the same workspace.'),
                        'anchor_text' => (string) ($sharedEntities->first() ?: ($item['target_title'] ?? 'related article')),
                        'placement' => 'body',
                    ];
                })
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
