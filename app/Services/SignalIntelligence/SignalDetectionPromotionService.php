<?php

namespace App\Services\SignalIntelligence;

use App\Enums\SignalStatus;
use App\Models\OpportunitySignal;
use App\Models\SignalDetection;
use App\Models\User;
use App\Services\OpportunityIntelligence\OpportunitySignalPayload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SignalDetectionPromotionService
{
    public function __construct(
        private readonly SignalDetectionToOpportunitySignalMapper $mapper,
    ) {
    }

    public function promote(SignalDetection $detection, User $user): OpportunitySignal
    {
        Gate::forUser($user)->authorize('update', $detection);

        return DB::transaction(function () use ($detection, $user): OpportunitySignal {
            $detection->loadMissing(['workspace', 'events']);

            $existing = $this->existingSignal($detection);

            if ($this->isPublished($detection) && $existing) {
                return $existing;
            }

            if (! $this->canPromote($detection)) {
                throw new AuthorizationException('This detection cannot be promoted from its current status.');
            }

            $signal = $existing ?: $this->createSignal($detection, $this->mapper->map($detection));

            $metadata = array_merge($detection->metadata ?? [], [
                'promoted_by' => (string) $user->id,
                'promoted_at' => now()->toIso8601String(),
                'opportunity_signal_id' => (string) $signal->id,
            ]);

            $detection->forceFill([
                'metadata' => $metadata,
            ])->save();

            $detection->markPublished();

            return $signal->refresh();
        });
    }

    private function canPromote(SignalDetection $detection): bool
    {
        $status = $detection->status instanceof SignalStatus
            ? $detection->status
            : SignalStatus::from((string) $detection->status);

        return in_array($status, [
            SignalStatus::NEW,
            SignalStatus::DETECTED,
            SignalStatus::REVIEWING,
        ], true);
    }

    private function isPublished(SignalDetection $detection): bool
    {
        $status = $detection->status instanceof SignalStatus
            ? $detection->status
            : SignalStatus::from((string) $detection->status);

        return $status === SignalStatus::PUBLISHED;
    }

    private function existingSignal(SignalDetection $detection): ?OpportunitySignal
    {
        return OpportunitySignal::query()
            ->where('workspace_id', $detection->workspace_id)
            ->where('metadata->signal_detection_id', (string) $detection->id)
            ->first();
    }

    private function createSignal(SignalDetection $detection, OpportunitySignalPayload $payload): OpportunitySignal
    {
        return OpportunitySignal::query()->updateOrCreate(
            [
                'workspace_id' => (string) $detection->workspace_id,
                'dedupe_hash' => $this->dedupeHash($detection),
            ],
            [
                'organization_id' => $detection->workspace->organization_id,
                'client_site_id' => $payload->clientSiteId,
                'content_id' => $payload->contentId,
                'content_cluster_id' => $payload->contentClusterId,
                'campaign_id' => $payload->campaignId,
                'source' => $payload->source->value,
                'category' => $payload->category?->value,
                'topic' => $payload->topic,
                'entity' => $payload->entity,
                'signal_strength' => max(0, min(100, $payload->signalStrength)),
                'confidence' => max(0, min(100, $payload->confidence)),
                'observed_at' => $payload->observedAt ?? now(),
                'metrics' => $payload->metrics,
                'evidence' => $payload->evidence,
                'metadata' => $payload->metadata,
            ]
        );
    }

    private function dedupeHash(SignalDetection $detection): string
    {
        return hash('sha256', implode('|', [
            (string) $detection->workspace_id,
            'signal_detection',
            (string) $detection->id,
        ]));
    }
}
