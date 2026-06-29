<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingOpportunity;
use App\Models\Opportunity;
use Illuminate\Support\Str;

class AgenticOpportunityActionSignatureService
{
    public const SIGNATURE_VERSION = 'mos-agentic-action:v1';

    public function __construct(
        private readonly AgenticOpportunityCanonicalMappingService $mapping,
    ) {}

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function forLegacyOpportunity(AgenticMarketingOpportunity $opportunity, string $actionType, array $context = []): array
    {
        $opportunity->loadMissing('objective');

        return $this->buildSignature($this->legacyContext($opportunity, $context), $actionType);
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function forCanonicalOpportunity(Opportunity $opportunity, string $actionType, array $context = []): array
    {
        $legacy = $opportunity->agenticMarketingOpportunity;

        if (! $legacy) {
            return $this->buildSignature([
                'canonical_opportunity_id' => $this->stringValue($opportunity->id),
                'workspace_id' => $this->stringValue($opportunity->workspace_id),
                'client_site_id' => $this->stringValue($opportunity->client_site_id),
                'content_id' => $this->stringValue($opportunity->content_id),
                'objective_id' => $this->stringValue(data_get($opportunity->metadata, 'objective_id')),
                'detector_key' => $this->stringValue(data_get($opportunity->metadata, 'detector_key') ?: data_get($opportunity->source_signal_summary, 'detector_key')),
                'agentic_type' => $this->stringValue(data_get($opportunity->metadata, 'agentic_type') ?: data_get($opportunity->source_signal_summary, 'opportunity_type')),
                'source_scoped_dedupe_key' => $this->stringValue(data_get($opportunity->metadata, 'source_scoped_dedupe_key') ?: $opportunity->dedupe_hash),
                'normalized_title_topic' => $this->normalizedTitleTopic($context, $opportunity->topic ?: $opportunity->title),
                'blocked_reasons' => ['missing_legacy_agentic_marketing_opportunity_link'],
            ], $actionType);
        }

        $legacyContext = $this->legacyContext($legacy, $context);
        $legacyContext['canonical_opportunity_id'] = $this->stringValue($opportunity->id);

        if ((string) $opportunity->workspace_id !== (string) $legacyContext['workspace_id']) {
            $legacyContext['blocked_reasons'][] = 'canonical_bridge_workspace_mismatch';
        }

        return $this->buildSignature($legacyContext, $actionType);
    }

    /**
     * @return array<string,mixed>
     */
    public function forAction(AgenticMarketingAction $action): array
    {
        $action->loadMissing('opportunity');

        if (! $action->opportunity) {
            return $this->buildSignature([
                'objective_id' => $this->stringValue($action->objective_id),
                'content_id' => $this->stringValue($action->content_id ?: data_get($action->payload, 'content_id')),
                'client_site_id' => $this->stringValue(data_get($action->payload, 'client_site_id')),
                'normalized_title_topic' => $this->normalizedTitleTopic((array) ($action->payload ?? []), null),
                'blocked_reasons' => ['missing_legacy_agentic_marketing_opportunity'],
            ], (string) $action->action_type);
        }

        return $this->forLegacyOpportunity($action->opportunity, (string) $action->action_type, [
            'content_id' => $action->content_id ?: data_get($action->payload, 'content_id'),
            'client_site_id' => data_get($action->payload, 'client_site_id'),
            'title' => data_get($action->payload, 'title'),
            'topic' => data_get($action->payload, 'proposal_details.topic') ?: data_get($action->payload, 'primary_keyword'),
            'target_locale' => data_get($action->payload, 'target_locale'),
        ]);
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    public function forCanonicalActionCandidate(Opportunity $opportunity, string $actionType, array $candidate = []): array
    {
        return $this->forCanonicalOpportunity($opportunity, $actionType, $candidate);
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function buildSignature(array $context, string $actionType): array
    {
        $context['action_type'] = $this->stringValue($actionType);
        $context['normalized_action_type'] = $this->normalizeActionType($actionType);

        $blockedReasons = $this->blockedReasons($context);
        $parts = [
            self::SIGNATURE_VERSION,
            'workspace:'.($context['workspace_id'] ?? 'missing'),
            'objective:'.($context['objective_id'] ?? 'missing'),
            'legacy_agentic_opportunity:'.($context['legacy_agentic_opportunity_id'] ?? 'missing'),
            'canonical_opportunity:'.($context['canonical_opportunity_id'] ?? 'none'),
            'detector:'.($context['detector_key'] ?? 'missing'),
            'agentic_type:'.($context['agentic_type'] ?? 'missing'),
            'action_type:'.($context['normalized_action_type'] ?? 'missing'),
            'content:'.($context['content_id'] ?? 'none'),
            'site:'.($context['client_site_id'] ?? 'none'),
            'source_scoped_dedupe_key:'.($context['source_scoped_dedupe_key'] ?? 'none'),
            'title_topic:'.($context['normalized_title_topic'] ?? 'none'),
        ];

        return [
            'signature' => $blockedReasons === [] ? hash('sha256', implode('|', $parts)) : null,
            'signature_version' => self::SIGNATURE_VERSION,
            'signature_parts' => $parts,
            'blocked_reasons' => $blockedReasons,
            'workspace_id' => $context['workspace_id'] ?? null,
            'objective_id' => $context['objective_id'] ?? null,
            'legacy_agentic_opportunity_id' => $context['legacy_agentic_opportunity_id'] ?? null,
            'canonical_opportunity_id' => $context['canonical_opportunity_id'] ?? null,
            'detector_key' => $context['detector_key'] ?? null,
            'agentic_type' => $context['agentic_type'] ?? null,
            'action_type' => $context['action_type'] ?? null,
            'normalized_action_type' => $context['normalized_action_type'] ?? null,
            'content_id' => $context['content_id'] ?? null,
            'client_site_id' => $context['client_site_id'] ?? null,
            'source_scoped_dedupe_key' => $context['source_scoped_dedupe_key'] ?? null,
            'normalized_title_topic' => $context['normalized_title_topic'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function legacyContext(AgenticMarketingOpportunity $opportunity, array $overrides): array
    {
        $mapping = $this->mapping->mapExisting($opportunity);
        $canonical = $this->linkedCanonicalOpportunity($opportunity, $blockedReasons);

        return [
            'workspace_id' => $this->stringValue($opportunity->objective?->workspace_id),
            'objective_id' => $this->stringValue($opportunity->objective_id),
            'legacy_agentic_opportunity_id' => $this->stringValue($opportunity->id),
            'canonical_opportunity_id' => $this->stringValue($canonical?->id),
            'detector_key' => $this->stringValue($mapping->detectorKey ?: data_get($opportunity->payload, 'detector')),
            'agentic_type' => $this->stringValue($opportunity->type),
            'content_id' => $this->stringValue($overrides['content_id'] ?? $opportunity->content_id ?: data_get($opportunity->payload, 'content_id')),
            'client_site_id' => $this->stringValue($overrides['client_site_id'] ?? $opportunity->objective?->client_site_id ?: data_get($opportunity->payload, 'client_site_id') ?: data_get($opportunity->payload, 'signals.client_site_id')),
            'source_scoped_dedupe_key' => $this->stringValue($mapping->dedupeKey ?: $opportunity->dedupe_hash ?: data_get($opportunity->payload, 'dedupe_key')),
            'normalized_title_topic' => $this->normalizedTitleTopic($overrides, data_get($opportunity->payload, 'topic') ?: data_get($opportunity->payload, 'signals.topic_keyword') ?: $opportunity->title),
            'blocked_reasons' => array_values(array_unique(array_merge($blockedReasons, $mapping->blockedReasons))),
        ];
    }

    /**
     * @param  array<int,string>|null  $blockedReasons
     */
    private function linkedCanonicalOpportunity(AgenticMarketingOpportunity $opportunity, ?array &$blockedReasons): ?Opportunity
    {
        $blockedReasons = [];
        $linked = Opportunity::query()
            ->where('agentic_marketing_opportunity_id', $opportunity->id)
            ->orderBy('id')
            ->get();

        if ($linked->count() > 1) {
            $blockedReasons[] = 'multiple_canonical_opportunities_linked_to_agentic_row';

            return null;
        }

        return $linked->first();
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<int,string>
     */
    private function blockedReasons(array $context): array
    {
        $required = [
            'workspace_id' => 'missing_workspace_id',
            'objective_id' => 'missing_objective_id',
            'legacy_agentic_opportunity_id' => 'missing_legacy_agentic_marketing_opportunity_id',
            'detector_key' => 'missing_detector_key',
            'agentic_type' => 'missing_agentic_type',
            'normalized_action_type' => 'missing_action_type',
        ];

        $missing = [];
        foreach ($required as $key => $reason) {
            if (! isset($context[$key]) || trim((string) $context[$key]) === '' || $context[$key] === 'unknown') {
                $missing[] = $reason;
            }
        }

        return array_values(array_unique(array_merge((array) ($context['blocked_reasons'] ?? []), $missing)));
    }

    private function normalizedTitleTopic(array $context, mixed $fallback): ?string
    {
        $value = $context['target_locale'] ?? null;
        if ($value !== null && trim((string) $value) !== '') {
            $value = 'locale:'.$value.'|'.($context['title'] ?? $fallback);
        } else {
            $value = $context['title'] ?? $context['topic'] ?? $fallback;
        }

        $value = $this->stringValue($value);

        return $value ? Str::of($value)->lower()->squish()->limit(180, '')->toString() : null;
    }

    private function normalizeActionType(string $actionType): ?string
    {
        return $this->stringValue(Str::of($actionType)->lower()->snake()->toString());
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
