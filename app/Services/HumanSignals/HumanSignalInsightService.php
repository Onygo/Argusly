<?php

namespace App\Services\HumanSignals;

use App\Enums\HumanSignalType;
use App\Models\HumanSignal;
use App\Models\HumanSignalInsight;
use Illuminate\Support\Str;

class HumanSignalInsightService
{
    public function generateForSignal(HumanSignal $signal): HumanSignalInsight
    {
        $type = $signal->type instanceof HumanSignalType
            ? $signal->type
            : (HumanSignalType::tryFrom((string) $signal->type) ?? HumanSignalType::CUSTOM);

        [$insight, $action] = $this->insightAndAction($signal, $type);

        return HumanSignalInsight::query()->updateOrCreate(
            ['human_signal_id' => (string) $signal->id],
            [
                'title' => Str::limit('Insight: '.$signal->title, 220, ''),
                'insight' => $insight,
                'recommended_action' => $action,
                'quality_score' => max(0, min(100, ((float) $signal->confidence_score * 0.65) + 25)),
                'metadata_json' => [
                    'generated_from' => 'human_signal',
                    'signal_type' => $type->value,
                    'generated_at' => now()->toIso8601String(),
                ],
            ]
        );
    }

    /**
     * @return array{0:string,1:string}
     */
    private function insightAndAction(HumanSignal $signal, HumanSignalType $type): array
    {
        $topic = (string) data_get($signal->metadata_json, 'topic', $signal->title);

        return match ($type) {
            HumanSignalType::FAQ_GAP => [
                "Important buyer questions around {$topic} are not covered deeply enough, so AI systems receive weaker context when summarizing the page.",
                'Add direct FAQ answers, FAQPage schema, and internal links from the strongest related page.',
            ],
            HumanSignalType::COMPETITOR_SHIFT => [
                "Competitors are becoming more visible around {$topic}, which means the market is actively shaping AI answer context before the brand does.",
                'Create a competitive response asset that clarifies entity positioning, proof points, and comparison angles.',
            ],
            HumanSignalType::CITATION_PATTERN => [
                "AI systems already show a citation pattern around {$topic}, so the next content should reinforce observed source behavior rather than introduce a generic angle.",
                'Use the cited theme as the lead insight and expand it into answer blocks, examples, and supporting evidence.',
            ],
            HumanSignalType::AUTHORITY_GROWTH => [
                "{$topic} is an authority pocket with enough observed traction to support a cluster expansion or campaign sequence.",
                'Build adjacent content that links back to the proven authority page and reuses the observed language patterns.',
            ],
            HumanSignalType::AUTHORITY_DECLINE => [
                "{$topic} is losing usable context or visibility, creating a risk that future AI answers rely on other sources.",
                'Refresh the page with clearer entity coverage, direct answers, citations, and updated examples.',
            ],
            HumanSignalType::CONTENT_PERFORMANCE, HumanSignalType::CONVERSION_PATTERN => [
                "{$topic} is producing measurable performance, which makes it a candidate for repeatable formats, social reuse, and campaign planning.",
                'Turn the pattern into a follow-up brief and test it across one additional channel.',
            ],
            HumanSignalType::CAMPAIGN_PATTERN => [
                "The campaign data shows a repeatable pattern, so planning can start from observed behavior instead of a fresh generic campaign prompt.",
                'Reuse the winning message, CTA, or channel pattern in the next campaign proposal.',
            ],
            HumanSignalType::EMERGING_TOPIC, HumanSignalType::TOPIC_OPPORTUNITY, HumanSignalType::CONTENT_GAP => [
                "{$topic} has become specific enough to prioritize as content intelligence instead of a loose topic idea.",
                'Create an opportunity and brief that names the detected gap, target page, and expected AI visibility outcome.',
            ],
            default => [
                "This signal captures a concrete observation that should anchor content and planning decisions before generic market advice is used.",
                'Use this observation as the lead planning constraint for the next generated asset.',
            ],
        };
    }
}
