<?php

namespace App\Services\BrandGrowthPlanning;

use App\Models\BrandGrowthAudienceProposal;
use App\Models\BrandGrowthPlan;
use App\Models\BrandGrowthPlanFinding;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class BrandGrowthPlanDiffService
{
    private const SECTION_FIELDS = [
        'business_objective' => 'Business objective',
        'brand_objective' => 'Brand objective',
        'planning_horizon' => 'Planning horizon',
        'recommended_primary_audiences' => 'Primary audiences',
        'recommended_secondary_audiences' => 'Secondary audiences',
        'priority_industries' => 'Priority industries',
        'buying_committee_roles' => 'Buying committee roles',
        'positioning_observations' => 'Positioning observations',
        'messaging_priorities' => 'Messaging priorities',
        'authority_priorities' => 'Authority priorities',
        'evidence_priorities' => 'Evidence priorities',
        'content_priorities' => 'Content priorities',
        'campaign_themes' => 'Campaign themes',
        'channel_recommendations' => 'Channel recommendations',
        'kpi_recommendations' => 'KPI recommendations',
        'top_prioritized_actions' => 'Top prioritized actions',
    ];

    /**
     * @return array<string, mixed>
     */
    public function diff(BrandGrowthPlan $plan, ?BrandGrowthPlan $baseline = null): array
    {
        $baseline ??= $plan->relationLoaded('supersedesPlan')
            ? $plan->supersedesPlan
            : ($plan->supersedes_plan_id ? BrandGrowthPlan::query()->find($plan->supersedes_plan_id) : null);

        if (! $baseline || (string) $baseline->workspace_id !== (string) $plan->workspace_id) {
            return $this->emptyDiff();
        }

        $plan->loadMissing(['findings', 'audienceProposals']);
        $baseline->loadMissing(['findings', 'audienceProposals']);

        $sections = $this->changedSections($baseline, $plan);
        $findings = $this->compareItems($baseline->findings, $plan->findings, 'finding');
        $audiences = $this->compareItems($baseline->audienceProposals, $plan->audienceProposals, 'audience');
        $missingInformation = $this->compareStringList($baseline->missing_information ?? [], $plan->missing_information ?? []);
        $confidenceDelta = round((float) $plan->confidence_score - (float) $baseline->confidence_score, 2);

        $hasChanges = $sections !== []
            || $findings['added_count'] > 0
            || $findings['removed_count'] > 0
            || $findings['changed_count'] > 0
            || $audiences['added_count'] > 0
            || $audiences['removed_count'] > 0
            || $audiences['changed_count'] > 0
            || $missingInformation['added'] !== []
            || $missingInformation['removed'] !== [];

        return [
            'baseline' => [
                'id' => (string) $baseline->id,
                'version' => (int) $baseline->version,
                'status' => $baseline->status?->value ?? $baseline->status,
                'generated_at' => $baseline->generated_at,
            ],
            'sections' => [
                'changed' => $sections,
                'changed_count' => count($sections),
            ],
            'findings' => $findings,
            'audiences' => $audiences,
            'missing_information' => [
                'added' => $missingInformation['added'],
                'resolved' => $missingInformation['removed'],
                'unchanged_count' => $missingInformation['unchanged_count'],
            ],
            'confidence' => [
                'previous' => (float) $baseline->confidence_score,
                'current' => (float) $plan->confidence_score,
                'delta' => $confidenceDelta,
            ],
            'summary' => [
                'added_findings' => $findings['added_count'],
                'updated_findings' => $findings['changed_count'],
                'removed_findings' => $findings['removed_count'],
                'added_audiences' => $audiences['added_count'],
                'updated_audiences' => $audiences['changed_count'],
                'removed_audiences' => $audiences['removed_count'],
                'changed_sections' => count($sections),
                'new_missing_information' => count($missingInformation['added']),
                'resolved_missing_information' => count($missingInformation['removed']),
            ],
            'has_changes' => $hasChanges,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyDiff(): array
    {
        return [
            'baseline' => null,
            'sections' => ['changed' => [], 'changed_count' => 0],
            'findings' => $this->emptyItemDiff(),
            'audiences' => $this->emptyItemDiff(),
            'missing_information' => ['added' => [], 'resolved' => [], 'unchanged_count' => 0],
            'confidence' => ['previous' => null, 'current' => null, 'delta' => null],
            'summary' => [
                'added_findings' => 0,
                'updated_findings' => 0,
                'removed_findings' => 0,
                'added_audiences' => 0,
                'updated_audiences' => 0,
                'removed_audiences' => 0,
                'changed_sections' => 0,
                'new_missing_information' => 0,
                'resolved_missing_information' => 0,
            ],
            'has_changes' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyItemDiff(): array
    {
        return [
            'added' => [],
            'removed' => [],
            'changed' => [],
            'unchanged_count' => 0,
            'added_count' => 0,
            'removed_count' => 0,
            'changed_count' => 0,
            'added_keys' => [],
            'removed_keys' => [],
            'changed_keys' => [],
            'states' => [],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function changedSections(BrandGrowthPlan $previous, BrandGrowthPlan $current): array
    {
        return collect(self::SECTION_FIELDS)
            ->map(function (string $label, string $field) use ($previous, $current): ?array {
                $previousValue = $previous->getAttribute($field);
                $currentValue = $current->getAttribute($field);

                if ($this->sameValue($previousValue, $currentValue)) {
                    return null;
                }

                return [
                    'key' => $field,
                    'label' => $label,
                    'previous_count' => $this->valueCount($previousValue),
                    'current_count' => $this->valueCount($currentValue),
                    'previous_preview' => $this->preview($previousValue),
                    'current_preview' => $this->preview($currentValue),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Model>  $previous
     * @param  Collection<int, Model>  $current
     * @return array<string, mixed>
     */
    private function compareItems(Collection $previous, Collection $current, string $kind): array
    {
        $previousItems = $this->keyedItems($previous, $kind);
        $currentItems = $this->keyedItems($current, $kind);

        $previousKeys = $previousItems->keys();
        $currentKeys = $currentItems->keys();

        $addedKeys = $currentKeys->diff($previousKeys)->values();
        $removedKeys = $previousKeys->diff($currentKeys)->values();
        $sharedKeys = $currentKeys->intersect($previousKeys)->values();

        $changedKeys = $sharedKeys
            ->filter(fn (string $key): bool => $currentItems[$key]['signature'] !== $previousItems[$key]['signature'])
            ->values();

        $states = $addedKeys->mapWithKeys(fn (string $key): array => [$key => 'added'])
            ->merge($changedKeys->mapWithKeys(fn (string $key): array => [$key => 'changed']))
            ->all();

        return [
            'added' => $addedKeys->map(fn (string $key): array => $currentItems[$key]['summary'])->values()->all(),
            'removed' => $removedKeys->map(fn (string $key): array => $previousItems[$key]['summary'])->values()->all(),
            'changed' => $changedKeys->map(fn (string $key): array => [
                'previous' => $previousItems[$key]['summary'],
                'current' => $currentItems[$key]['summary'],
            ])->values()->all(),
            'unchanged_count' => $sharedKeys->diff($changedKeys)->count(),
            'added_count' => $addedKeys->count(),
            'removed_count' => $removedKeys->count(),
            'changed_count' => $changedKeys->count(),
            'added_keys' => $addedKeys->all(),
            'removed_keys' => $removedKeys->all(),
            'changed_keys' => $changedKeys->all(),
            'states' => $states,
        ];
    }

    /**
     * @param  Collection<int, Model>  $items
     * @return Collection<string, array<string, mixed>>
     */
    private function keyedItems(Collection $items, string $kind): Collection
    {
        return $items
            ->mapWithKeys(function (Model $item) use ($kind): array {
                $key = $this->itemKey($item, $kind);

                return [
                    $key => [
                        'summary' => $this->itemSummary($item, $key, $kind),
                        'signature' => $this->itemSignature($item, $kind),
                    ],
                ];
            });
    }

    private function itemKey(Model $item, string $kind): string
    {
        $dedupeHash = trim((string) $item->getAttribute('dedupe_hash'));

        if ($dedupeHash !== '') {
            return $dedupeHash;
        }

        $parts = $kind === 'finding'
            ? [
                $item->getAttribute('type')?->value ?? $item->getAttribute('type'),
                $item->getAttribute('title'),
                $item->getAttribute('affected_audience'),
                $item->getAttribute('affected_industry'),
                $item->getAttribute('affected_funnel_stage'),
            ]
            : [
                $item->getAttribute('proposal_type')?->value ?? $item->getAttribute('proposal_type'),
                $item->getAttribute('source_type')?->value ?? $item->getAttribute('source_type'),
                $item->getAttribute('name'),
                $item->getAttribute('role'),
                $item->getAttribute('industry'),
            ];

        return hash('sha256', collect($parts)
            ->map(fn (mixed $part): string => mb_strtolower(trim((string) $part)))
            ->implode('|'));
    }

    /**
     * @return array<string, mixed>
     */
    private function itemSummary(Model $item, string $key, string $kind): array
    {
        if ($kind === 'finding' && $item instanceof BrandGrowthPlanFinding) {
            return [
                'key' => $key,
                'id' => (string) $item->id,
                'title' => $item->title,
                'type' => $item->type?->value ?? $item->type,
                'description' => $this->preview($item->description),
                'recommended_action' => $this->preview($item->recommended_action),
                'impact_score' => (float) $item->impact_score,
                'urgency_score' => (float) $item->urgency_score,
                'confidence_score' => (float) $item->confidence_score,
            ];
        }

        /** @var BrandGrowthAudienceProposal $item */
        return [
            'key' => $key,
            'id' => (string) $item->id,
            'title' => $item->name,
            'type' => $item->proposal_type?->value ?? $item->proposal_type,
            'role' => $item->role,
            'industry' => $item->industry,
            'confidence_score' => (float) $item->confidence_score,
        ];
    }

    private function itemSignature(Model $item, string $kind): string
    {
        $fields = $kind === 'finding'
            ? [
                'type',
                'title',
                'description',
                'rationale',
                'impact_score',
                'urgency_score',
                'confidence_score',
                'affected_audience',
                'affected_industry',
                'affected_funnel_stage',
                'recommended_action',
                'source_references',
                'source_summary',
            ]
            : [
                'proposal_type',
                'source_type',
                'name',
                'role',
                'seniority',
                'department',
                'industry',
                'company_size',
                'responsibilities',
                'goals',
                'pain_points',
                'objections',
                'buying_triggers',
                'kpis',
                'preferred_content',
                'buying_stage_relevance',
                'buying_committee_role',
                'confidence_score',
                'source_references',
            ];

        return $this->encodedComparable(
            collect($fields)->mapWithKeys(fn (string $field): array => [$field => $item->getAttribute($field)])->all()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function compareStringList(mixed $previous, mixed $current): array
    {
        $previousItems = $this->keyedStrings($previous);
        $currentItems = $this->keyedStrings($current);
        $previousKeys = $previousItems->keys();
        $currentKeys = $currentItems->keys();

        return [
            'added' => $currentKeys->diff($previousKeys)->map(fn (string $key): string => $currentItems[$key])->values()->all(),
            'removed' => $previousKeys->diff($currentKeys)->map(fn (string $key): string => $previousItems[$key])->values()->all(),
            'unchanged_count' => $currentKeys->intersect($previousKeys)->count(),
        ];
    }

    /**
     * @return Collection<string, string>
     */
    private function keyedStrings(mixed $items): Collection
    {
        return collect(Arr::wrap($items))
            ->flatten()
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->unique(fn (string $item): string => mb_strtolower($item))
            ->mapWithKeys(fn (string $item): array => [mb_strtolower($item) => $item]);
    }

    private function sameValue(mixed $previous, mixed $current): bool
    {
        return $this->encodedComparable($previous) === $this->encodedComparable($current);
    }

    private function encodedComparable(mixed $value): string
    {
        return (string) json_encode($this->normalizeComparable($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function normalizeComparable(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (is_array($value)) {
            $normalized = collect($value)
                ->map(fn (mixed $item): mixed => $this->normalizeComparable($item))
                ->all();

            if (Arr::isAssoc($normalized)) {
                ksort($normalized);
            }

            return $normalized;
        }

        if (is_float($value) || is_int($value)) {
            return round((float) $value, 2);
        }

        if (is_string($value)) {
            return trim($value);
        }

        return $value;
    }

    private function valueCount(mixed $value): int
    {
        if (is_array($value) || $value instanceof Collection) {
            return collect($value)->count();
        }

        return trim((string) $value) === '' ? 0 : 1;
    }

    private function preview(mixed $value): ?string
    {
        if (is_array($value) || $value instanceof Collection) {
            $text = collect($value)
                ->take(3)
                ->map(fn (mixed $item): string => $this->previewItem($item))
                ->filter()
                ->implode(' | ');

            return $text === '' ? null : Str::limit($text, 180);
        }

        $text = trim((string) $value);

        return $text === '' ? null : Str::limit($text, 180);
    }

    private function previewItem(mixed $item): string
    {
        if (is_array($item)) {
            return trim((string) ($item['title'] ?? $item['action'] ?? $item['recommended_action'] ?? $item['summary'] ?? $item['name'] ?? json_encode($item, JSON_UNESCAPED_SLASHES)));
        }

        return trim((string) $item);
    }
}
