<?php

namespace App\Services\Attribution;

use App\Models\AttributionConversion;
use App\Models\AttributionTouchpoint;
use App\Models\Connectors\Normalized\NormalizedCrmDeal;
use App\Models\Connectors\Normalized\NormalizedDailyPerformance;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class AttributionCanonicalProjector
{
    public function project(Workspace|string $workspace, Carbon|string $start, Carbon|string $end): array
    {
        $workspaceId = $workspace instanceof Workspace ? (string) $workspace->id : (string) $workspace;
        $start = Carbon::parse($start)->startOfDay();
        $end = Carbon::parse($end)->endOfDay();

        return [
            'touchpoints' => $this->projectTouchpoints($workspaceId, $start, $end),
            'conversions' => $this->projectConversions($workspaceId, $start, $end),
        ];
    }

    private function projectTouchpoints(string $workspaceId, Carbon $start, Carbon $end): int
    {
        $count = 0;

        NormalizedDailyPerformance::query()
            ->forWorkspace($workspaceId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('date')
            ->chunkById(250, function ($rows) use (&$count): void {
                foreach ($rows as $row) {
                    $touchpointKey = 'performance:'.$row->provider.':'.$row->entity_type.':'.$row->entity_id.':'.$row->date->toDateString();
                    $rawReference = (array) ($row->raw_reference ?? []);

                    AttributionTouchpoint::query()->updateOrCreate(
                        [
                            'workspace_id' => $row->workspace_id,
                            'touchpoint_key' => $touchpointKey,
                        ],
                        [
                            'anonymous_or_contact_key' => $rawReference['anonymous_or_contact_key'] ?? $rawReference['session_key'] ?? 'campaign:'.$row->provider.':'.$row->entity_id,
                            'occurred_at' => $row->date->copy()->setTime(12, 0),
                            'channel' => $row->provider,
                            'source' => $rawReference['source'] ?? $row->provider,
                            'medium' => $rawReference['medium'] ?? $row->entity_type,
                            'campaign_id' => $row->entity_type === 'campaign' ? $row->entity_id : ($rawReference['campaign_id'] ?? null),
                            'ad_group_id' => $row->entity_type === 'ad_group' ? $row->entity_id : ($rawReference['ad_group_id'] ?? null),
                            'ad_id' => $row->entity_type === 'ad' ? $row->entity_id : ($rawReference['ad_id'] ?? null),
                            'landing_page' => $rawReference['landing_page'] ?? $rawReference['landing_url'] ?? null,
                            'referrer' => $rawReference['referrer'] ?? null,
                            'session_key' => $rawReference['session_key'] ?? null,
                            'raw_reference' => array_merge($rawReference, [
                                'normalized_daily_performance_id' => (string) $row->id,
                                'provider' => $row->provider,
                                'entity_type' => $row->entity_type,
                                'entity_id' => $row->entity_id,
                            ]),
                        ],
                    );

                    $count++;
                }
            });

        return $count;
    }

    private function projectConversions(string $workspaceId, Carbon $start, Carbon $end): int
    {
        $count = 0;

        NormalizedCrmDeal::query()
            ->with(['contact'])
            ->forWorkspace($workspaceId)
            ->where(function ($query) use ($start, $end): void {
                $query->whereBetween('close_date', [$start->toDateString(), $end->toDateString()])
                    ->orWhereBetween('updated_at', [$start, $end]);
            })
            ->orderBy('updated_at')
            ->chunkById(250, function ($deals) use (&$count): void {
                foreach ($deals as $deal) {
                    $status = strtolower((string) $deal->status);
                    $conversionType = in_array($status, ['won', 'closed_won', 'true'], true) ? 'revenue' : 'opportunity';
                    $occurredAt = $deal->close_date?->copy()->endOfDay() ?: $deal->updated_at;
                    $contactKey = $deal->contact?->email_hash;

                    AttributionConversion::query()->updateOrCreate(
                        [
                            'workspace_id' => $deal->workspace_id,
                            'conversion_key' => 'crm_deal:'.$deal->provider.':'.$deal->provider_deal_id,
                        ],
                        [
                            'contact_key' => $contactKey,
                            'email_hash' => $contactKey,
                            'deal_id' => $deal->id,
                            'conversion_type' => $conversionType,
                            'occurred_at' => $occurredAt,
                            'value' => $deal->amount,
                            'currency' => $deal->currency,
                            'status' => $deal->status,
                            'raw_reference' => array_merge((array) ($deal->raw_reference ?? []), [
                                'normalized_crm_deal_id' => (string) $deal->id,
                                'normalized_crm_contact_id' => $deal->contact_id ? (string) $deal->contact_id : null,
                                'email_hash' => $contactKey,
                            ]),
                        ],
                    );

                    $count++;
                }
            });

        return $count;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function upsertTouchpoint(array $attributes): AttributionTouchpoint
    {
        $attributes['touchpoint_key'] ??= hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));

        return AttributionTouchpoint::query()->updateOrCreate(
            [
                'workspace_id' => $attributes['workspace_id'],
                'touchpoint_key' => $attributes['touchpoint_key'],
            ],
            $attributes,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function upsertConversion(array $attributes): AttributionConversion
    {
        $attributes['conversion_key'] ??= (string) Str::uuid();

        return AttributionConversion::query()->updateOrCreate(
            [
                'workspace_id' => $attributes['workspace_id'],
                'conversion_key' => $attributes['conversion_key'],
            ],
            $attributes,
        );
    }
}
