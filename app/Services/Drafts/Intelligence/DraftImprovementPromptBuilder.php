<?php

namespace App\Services\Drafts\Intelligence;

use App\Enums\DraftImprovementAction;
use App\Models\Draft;

class DraftImprovementPromptBuilder
{
    public function __construct(
        private readonly DraftContentSnapshotBuilder $snapshotBuilder,
        private readonly DraftSignalExtractor $signalExtractor,
        private readonly DraftMetricScorer $metricScorer,
        private readonly DraftIntelligenceRubricRegistry $rubrics,
    ) {}

    public function systemPrompt(DraftImprovementAction $action): string
    {
        if ($action === DraftImprovementAction::HUMAN_CONTENT) {
            return <<<'PROMPT'
You improve one Argusly HTML draft specifically to raise its Human Content score.

Return strict JSON only. Preserve facts, source meaning, internal links, SEO intent, schema compatibility, brand voice, entities, and CTA intent. Improve only the parts that weaken human/editorial quality: central thesis, reader tension, evidence, practical implications, expert judgment, specificity, narrative flow, rhythm, curiosity, non-generic headings, and AI-fingerprint resistance.
Do not blindly rewrite the whole article. Do not add unsupported claims. Do not create a generic intro/body/conclusion article shape.
Requested action: human_content.
Return JSON with: title, content_html, change_notes (array of concise strings), and seo { seo_title, seo_meta_description, seo_h1 }.
PROMPT;
        }

        if ($action === DraftImprovementAction::FULL_DRAFT) {
            return <<<'PROMPT'
You improve one Argusly HTML draft holistically.

Return strict JSON only. Preserve the draft's meaning, factual intent, coherent structure, and brand tone while improving SEO, readability, headings, CTA, LLM visibility, brand voice fit, trust/evidence, conversion fit, and publish readiness together.
Also improve how clearly AI systems can extract the main answer, summary passages, and named entities without turning the draft into generic AI bait.
Do not over-optimize one metric at the expense of another. Make the article more publishable as a whole.
Requested action: improve_full_draft.
Return JSON with: title, content_html, change_notes (array of concise strings), and seo { seo_title, seo_meta_description, seo_h1 }.
PROMPT;
        }

        return sprintf(
            'You improve one Argusly HTML draft with a focused %s pass. Return strict JSON only, preserve meaning and structure, and change only what is necessary for the requested action.',
            $action->summaryLabel()
        );
    }

    public function userPrompt(Draft $draft, DraftImprovementAction $action): string
    {
        $freshDraft = $this->snapshotBuilder->freshDraft($draft);
        $snapshot = $this->snapshotBuilder->build($freshDraft);
        $signals = $this->signalExtractor->extract($freshDraft, $snapshot);
        $baseline = $this->metricScorer->score($snapshot, $signals);
        $metricFocus = $this->metricFocus($action);

        return json_encode([
            'action' => [
                'key' => $action->value,
                'label' => $action->label(),
                'description' => $action->description(),
                'instructions' => $action->instructions(),
                'allowed_field_updates' => $action->allowsSeoFieldUpdates()
                    ? ['content_html', 'title', 'seo.seo_title', 'seo.seo_meta_description', 'seo.seo_h1']
                    : ['content_html'],
            ],
            'shared_rubrics' => $this->rubrics->all(),
            'metric_focus' => $metricFocus,
            'brief' => [
                'primary_keyword' => $freshDraft->brief?->primary_keyword,
                'secondary_keywords' => (array) ($freshDraft->brief?->secondary_keywords ?? []),
                'call_to_action' => $freshDraft->brief?->call_to_action,
                'target_audience' => $freshDraft->brief?->target_audience ?: $freshDraft->brief?->audience,
                'tone_of_voice' => $freshDraft->brief?->tone_of_voice,
                'funnel_stage' => $freshDraft->brief?->funnel_stage,
                'content_type' => $freshDraft->brief?->content_type,
            ],
            'brand_context' => [
                'brand_voice' => (array) ($snapshot['brand_voice'] ?? []),
                'company_profile' => (array) ($snapshot['company_profile'] ?? []),
            ],
            'current_snapshot' => [
                'title' => $snapshot['title'],
                'seo_title' => $snapshot['seo_title'],
                'seo_meta_description' => $snapshot['seo_meta_description'],
                'seo_h1' => $snapshot['seo_h1'],
                'intro' => $snapshot['intro'],
                'conclusion' => $snapshot['conclusion'],
                'headings' => $snapshot['headings'],
                'cta_candidate_blocks' => $snapshot['cta_candidate_blocks'],
                'plain_text_excerpt' => $this->limitText((string) $snapshot['plain_text'], 9000),
                'content_html' => $snapshot['content_html'],
            ],
            'deterministic_signals' => $signals,
            'current_scores' => [
                'seo' => data_get($baseline, 'sections.seo.score'),
                'readability' => data_get($baseline, 'sections.readability.score'),
                'cta' => data_get($baseline, 'sections.cta.score'),
                'headings' => data_get($baseline, 'sections.structure.score'),
                'llm_visibility' => data_get($baseline, 'sections.llm_visibility.score'),
                'brand_voice_fit' => data_get($baseline, 'sections.brand_voice_fit.score'),
                'conversion_fit' => data_get($baseline, 'sections.conversion_fit.score'),
                'trust_evidence' => data_get($baseline, 'sections.trust_evidence.score'),
                'publish_readiness' => data_get($baseline, 'sections.publish_readiness.score'),
                'human_content' => data_get($freshDraft->meta, 'human_content.after.human_content_score'),
                'editorial_quality' => data_get($freshDraft->meta, 'human_content.after.editorial_quality_score'),
                'originality' => data_get($freshDraft->meta, 'human_content.after.originality_score'),
                'ai_fingerprint' => data_get($freshDraft->meta, 'human_content.after.ai_fingerprint_score'),
                'publish_gate_status' => data_get($freshDraft->meta, 'publish_gate_status'),
            ],
            'current_explanations' => [
                'seo' => data_get($baseline, 'sections.seo.explanation'),
                'readability' => data_get($baseline, 'sections.readability.explanation'),
                'cta' => data_get($baseline, 'sections.cta.explanation'),
                'headings' => data_get($baseline, 'sections.structure.explanation'),
                'llm_visibility' => data_get($baseline, 'sections.llm_visibility.explanation'),
                'brand_voice_fit' => data_get($baseline, 'sections.brand_voice_fit.explanation'),
                'conversion_fit' => data_get($baseline, 'sections.conversion_fit.explanation'),
                'trust_evidence' => data_get($baseline, 'sections.trust_evidence.explanation'),
                'publish_readiness' => data_get($baseline, 'sections.publish_readiness.explanation'),
                'human_content_findings' => data_get($freshDraft->meta, 'human_content.after.findings', data_get($freshDraft->meta, 'fingerprint_findings', [])),
                'human_content_recommendations' => data_get($freshDraft->meta, 'human_content.after.recommendations', []),
                'human_content_gate_reasons' => data_get($freshDraft->meta, 'human_content_gate.reasons', []),
                'humanization_actions' => data_get($freshDraft->meta, 'human_content.after.suggested_humanization_actions', []),
            ],
            'instructions' => [
                'Keep valid HTML.',
                $action === DraftImprovementAction::FULL_DRAFT
                    ? 'Make coordinated edits across the article while preserving meaning and tone.'
                    : ($action === DraftImprovementAction::HUMAN_CONTENT
                        ? 'Prioritize Human Content gate blockers and score dimensions while keeping SEO metadata and link targets stable.'
                        : 'Keep the rest of the article stable when a local fix is enough.'),
                'Use the same rubric standards that drive the intelligence scan.',
                'Keep keyword usage natural and preserve readability.',
                'Keep headings coherent with the body copy.',
                'When useful, strengthen answer-first phrasing, concise summary passages, and explicit named entities so the draft is easier for AI systems to extract accurately.',
                'Keep the tone aligned with the brand voice and target audience when guidance is available.',
                'Prefer concrete framing, measured claims, and practical examples over hype.',
                'Support the CTA with enough context that the reader can take the intended next step confidently.',
                'Return the full updated content_html, not a partial fragment.',
                $action === DraftImprovementAction::FULL_DRAFT
                    ? 'Return change_notes as a concise list of the most important improvements made.'
                    : 'Return change_summary as a concise description of the focused improvement.',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';
    }

    /**
     * @return array<string,mixed>
     */
    private function metricFocus(DraftImprovementAction $action): array
    {
        return match ($action) {
            DraftImprovementAction::FULL_DRAFT => [
                'primary_metrics' => ['seo', 'readability', 'cta', 'headings', 'llm_visibility', 'brand_voice_fit', 'conversion_fit', 'trust_evidence', 'publish_readiness'],
                'summary' => 'Optimize the whole article holistically so search relevance, readability, CTA, AI extractability, trust, brand voice, conversion fit, and publish readiness improve together.',
            ],
            DraftImprovementAction::HUMAN_CONTENT => [
                'primary_metrics' => ['human_content', 'editorial_quality', 'originality', 'ai_fingerprint', 'narrative_flow', 'human_voice', 'expertise', 'evidence_usage', 'rhythm', 'curiosity', 'publish_readiness'],
                'summary' => 'Raise the Human Content score by making the draft more editorially specific, evidence-led, varied in rhythm, less generic, and ready for the Human Content publishing gate.',
            ],
            DraftImprovementAction::SEO => [
                'primary_metrics' => ['seo'],
                'summary' => 'Focus on search relevance and metadata without degrading readability.',
            ],
            DraftImprovementAction::READABILITY => [
                'primary_metrics' => ['readability'],
                'summary' => 'Improve clarity, flow, and scanability without removing important keywords.',
            ],
            DraftImprovementAction::CTA => [
                'primary_metrics' => ['cta'],
                'summary' => 'Add or improve a CTA that matches topic, audience, and funnel stage.',
            ],
            DraftImprovementAction::HEADINGS => [
                'primary_metrics' => ['headings'],
                'summary' => 'Improve heading clarity and hierarchy while staying aligned with the content.',
            ],
        };
    }

    private function limitText(string $plainText, int $maxCharacters): string
    {
        if (mb_strlen($plainText) <= $maxCharacters) {
            return trim($plainText);
        }

        return trim(mb_substr($plainText, 0, $maxCharacters));
    }
}
