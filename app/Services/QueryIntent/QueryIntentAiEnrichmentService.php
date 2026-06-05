<?php

namespace App\Services\QueryIntent;

use App\DTO\QueryIntent\QueryIntentInput;

class QueryIntentAiEnrichmentService
{
    /**
     * @return array<string, mixed>
     */
    public function enrich(QueryIntentInput $input, array $classification): array
    {
        $topic = $input->query ?: $input->title ?: 'Untitled opportunity';

        return [
            'status' => 'ready_for_ai',
            'summary' => sprintf(
                '%s is classified as %s intent for %s audiences at the %s stage.',
                $topic,
                $classification['primary_intent'],
                str_replace('_', ' ', $classification['buyer_role']),
                $classification['funnel_stage']
            ),
            'prompt_context' => [
                'task' => 'Refine query intent, buyer role, urgency, and business impact for a content opportunity.',
                'input' => [
                    'title' => $input->title,
                    'query' => $input->query,
                    'text' => $input->text,
                    'context' => $input->context,
                ],
                'deterministic_classification' => $classification,
                'expected_json_schema' => [
                    'primary_intent' => QueryIntentTaxonomy::INTENTS,
                    'funnel_stage' => QueryIntentTaxonomy::FUNNEL_STAGES,
                    'buyer_role' => QueryIntentTaxonomy::BUYER_ROLES,
                    'urgency' => QueryIntentTaxonomy::URGENCY_LEVELS,
                    'business_impact' => QueryIntentTaxonomy::BUSINESS_IMPACT_LEVELS,
                    'rationale' => 'string',
                    'recommended_content_angle' => 'string',
                ],
            ],
            'recommended_content_angle' => $this->angle($classification['primary_intent'], $classification['buyer_role'], $topic),
        ];
    }

    private function angle(string $intent, string $buyerRole, string $topic): string
    {
        return match ($intent) {
            'comparison' => 'Create a direct comparison that resolves objections, proof gaps, and conversion questions for ' . str_replace('_', ' ', $buyerRole) . '.',
            'migration' => 'Frame ' . $topic . ' as a switching plan with risks, timeline, data/process migration, and success criteria.',
            'risk_evaluation' => 'Lead with risk reduction, governance, compliance, reliability, and executive-safe proof.',
            'implementation' => 'Turn the topic into a practical workflow with steps, prerequisites, examples, and internal links.',
            'transactional' => 'Connect the topic to pricing, demo readiness, objections, outcomes, and next-step CTAs.',
            default => 'Answer the core question clearly, then guide the reader toward the next funnel stage.',
        };
    }
}
