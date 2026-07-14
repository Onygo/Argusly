<?php

namespace App\Services\OpportunityIntelligence;

use App\Enums\DraftType;
use App\Enums\SupportedLanguage;
use App\Models\Brief;
use Illuminate\Support\Str;

class BriefToDraftMapper
{
    /**
     * @return array<string,mixed>
     */
    public function map(Brief $brief): array
    {
        $sourceContext = $this->sourceContext($brief);

        return [
            'brief_id' => (string) $brief->id,
            'content_id' => $brief->content_id,
            'client_site_id' => (string) $brief->client_site_id,
            'content_destination_id' => $brief->content_destination_id,
            'status' => 'draft',
            'title' => Str::limit((string) ($brief->title ?: 'First draft'), 180, ''),
            'seo_title' => Str::limit((string) ($brief->title ?: 'First draft'), 180, ''),
            'seo_h1' => Str::limit((string) ($brief->title ?: 'First draft'), 180, ''),
            'seo_meta_description' => Str::limit($this->plainText((string) ($brief->unique_angle ?: $brief->notes ?: $brief->title)), 155, ''),
            'output_type' => (string) ($brief->output_type ?: $brief->content_type ?: 'article'),
            'language' => SupportedLanguage::tryFromString((string) $brief->language)?->value ?? SupportedLanguage::default()->value,
            'draft_type' => DraftType::ORIGINAL->value,
            'model_used' => null,
            'content_html' => $this->starterHtml($brief, $sourceContext),
            'meta' => [
                'source' => 'opportunity_execution_plan',
                'generation_mode' => 'manual_starter_outline',
                'generate_later' => true,
                'source_context' => $sourceContext,
                'client_refs' => $sourceContext,
            ],
            'links' => [],
            'delivery_status' => 'pending',
            'delivery_attempts' => 0,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function sourceContext(Brief $brief): array
    {
        $refs = is_array($brief->client_refs) ? $brief->client_refs : [];

        return [
            'source' => 'opportunity_execution_plan',
            'brief_id' => (string) $brief->id,
            'execution_plan_id' => (string) ($refs['execution_plan_id'] ?? $refs['opportunity_execution_plan_id'] ?? ''),
            'opportunity_execution_plan_id' => (string) ($refs['opportunity_execution_plan_id'] ?? $refs['execution_plan_id'] ?? ''),
            'opportunity_id' => (string) ($refs['opportunity_id'] ?? ''),
            'workspace_id' => (string) ($refs['workspace_id'] ?? $brief->clientSite?->workspace_id ?? ''),
            'brand_growth_plan_id' => (string) ($refs['brand_growth_plan_id'] ?? ''),
            'brand_growth_plan_finding_ids' => array_values((array) ($refs['brand_growth_plan_finding_ids'] ?? [])),
            'brand_growth_plan' => $refs['brand_growth_plan'] ?? [],
            'signal_detection_ids' => array_values((array) ($refs['signal_detection_ids'] ?? [])),
            'signal_event_ids' => array_values((array) ($refs['signal_event_ids'] ?? [])),
            'evidence_summary' => $refs['evidence_summary'] ?? [],
            'planned_steps' => array_values((array) ($refs['planned_steps'] ?? [])),
            'recommended_actions' => array_values((array) ($refs['recommended_actions'] ?? [])),
        ];
    }

    /**
     * @param array<string,mixed> $sourceContext
     */
    private function starterHtml(Brief $brief, array $sourceContext): string
    {
        $title = $this->escape((string) ($brief->title ?: 'First draft'));
        $objective = $this->escape($this->plainText((string) ($brief->intent ?: $brief->notes ?: $brief->unique_angle)));
        $audience = $this->escape((string) ($brief->target_audience ?: $brief->audience ?: 'Target audience to refine'));
        $angle = $this->escape((string) ($brief->unique_angle ?: 'Add the strongest evidence-led angle here.'));
        $cta = $this->escape((string) ($brief->call_to_action ?: 'Define the next step for the reader.'));

        $steps = collect($sourceContext['planned_steps'] ?? [])
            ->map(fn ($step): string => $this->escape(trim((string) (($step['title'] ?? 'Step').' - '.($step['description'] ?? '')))))
            ->filter()
            ->take(7)
            ->values();

        $evidenceItems = collect($sourceContext['signal_detection_ids'] ?? [])
            ->map(fn ($id): string => $this->escape((string) $id))
            ->filter()
            ->values();

        $html = [
            '<article data-source="opportunity_execution_plan">',
            '<h1>'.$title.'</h1>',
            '<p><strong>Objective:</strong> '.$objective.'</p>',
            '<p><strong>Audience:</strong> '.$audience.'</p>',
            '<h2>Working angle</h2>',
            '<p>'.$angle.'</p>',
            '<h2>Draft outline</h2>',
            '<ol>',
        ];

        if ($steps->isNotEmpty()) {
            foreach ($steps as $step) {
                $html[] = '<li>'.$step.'</li>';
            }
        } else {
            $html[] = '<li>Open with the customer problem and current market context.</li>';
            $html[] = '<li>Explain the opportunity using the available evidence.</li>';
            $html[] = '<li>Translate the opportunity into practical recommendations.</li>';
        }

        $html[] = '</ol>';
        $html[] = '<h2>Evidence to use</h2>';

        if ($evidenceItems->isNotEmpty()) {
            $html[] = '<ul>';
            foreach ($evidenceItems as $id) {
                $html[] = '<li>SignalDetection '.$id.'</li>';
            }
            $html[] = '</ul>';
        } else {
            $html[] = '<p>Use the linked opportunity and execution plan evidence.</p>';
        }

        $html[] = '<h2>Call to action</h2>';
        $html[] = '<p>'.$cta.'</p>';
        $html[] = '</article>';

        return implode("\n", $html);
    }

    private function plainText(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strip_tags($value)));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
