<?php

namespace App\Services\Signals;

use App\Contracts\Signals\SignalProducer;
use App\Models\Account;
use App\Models\Brand;
use App\Models\IntelligenceSignal;
use App\Services\EvidenceService;
use App\Services\AlertService;
use App\Services\RecommendationEngineService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class SignalManager
{
    /**
     * @param  iterable<int, SignalProducer>  $producers
     */
    public function __construct(private readonly iterable $producers = []) {}

    public function produce(object $event): ?IntelligenceSignal
    {
        foreach ($this->producers as $producer) {
            if ($producer->supports($event)) {
                return $producer->produce($event);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function record(Account $account, array $attributes, ?Brand $brand = null, bool $generateRecommendations = true): IntelligenceSignal
    {
        $this->validate($attributes);
        $evidenceItems = $attributes['evidence'] ?? [];
        $evidenceSubject = $attributes['evidence_subject'] ?? null;
        unset($attributes['evidence'], $attributes['evidence_subject']);

        if ($brand && $brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Signal brand must belong to the signal account.');
        }

        $dedupeKey = $attributes['dedupe_key'] ?? null;
        $payload = [
            ...$attributes,
            'account_id' => $account->id,
            'brand_id' => $brand?->id,
            'status' => $attributes['status'] ?? 'new',
            'detected_at' => $attributes['detected_at'] ?? now(),
        ];

        if (! $dedupeKey) {
            $signal = IntelligenceSignal::query()->create($payload);
            $this->attachEvidence($signal, $evidenceItems, $evidenceSubject);
            if ($generateRecommendations) {
                app(RecommendationEngineService::class)->generateForSignal($signal);
            }
            app(AlertService::class)->triggerForSignal($signal);

            return $signal;
        }

        $signal = IntelligenceSignal::query()
            ->where('account_id', $account->id)
            ->where('dedupe_key', $dedupeKey)
            ->first();

        if (! $signal) {
            $signal = IntelligenceSignal::query()->create($payload);
            $this->attachEvidence($signal, $evidenceItems, $evidenceSubject);
            if ($generateRecommendations) {
                app(RecommendationEngineService::class)->generateForSignal($signal);
            }
            app(AlertService::class)->triggerForSignal($signal);

            return $signal;
        }

        $signal->forceFill([
            ...$payload,
            'status' => in_array($signal->status, ['dismissed', 'resolved'], true) ? $signal->status : $payload['status'],
        ])->save();

        $signal = $signal->refresh();
        $this->attachEvidence($signal, $evidenceItems, $evidenceSubject);
        if ($generateRecommendations) {
            app(RecommendationEngineService::class)->generateForSignal($signal);
        }
        app(AlertService::class)->triggerForSignal($signal);

        return $signal;
    }

    /**
     * @param  array<int, array<string, mixed>>  $evidenceItems
     */
    private function attachEvidence(IntelligenceSignal $signal, array $evidenceItems, ?object $evidenceSubject): void
    {
        if ($signal->evidenceItems()->exists()) {
            return;
        }

        $evidence = app(EvidenceService::class);

        if ($evidenceSubject instanceof Model) {
            $evidence->copyBetweenSubjects($evidenceSubject, $signal);
        }

        foreach ($evidenceItems as $item) {
            $evidence->createForSubject($signal, [
                'source_id' => $item['source_id'] ?? null,
                'evidence_type' => $item['evidence_type'] ?? 'provider_payload',
                'title' => $item['title'] ?? $signal->title,
                'url' => $item['url'] ?? null,
                'snippet' => $item['snippet'] ?? $signal->summary,
                'raw_payload' => $item['raw_payload'] ?? $signal->payload,
                'confidence_score' => $item['confidence_score'] ?? $signal->confidence_score,
                'captured_at' => $item['captured_at'] ?? $signal->detected_at,
            ]);
        }
    }

    /**
     * @return Collection<int, SignalProducer>
     */
    public function producers(): Collection
    {
        return collect($this->producers);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function validate(array $attributes): void
    {
        foreach (['type', 'priority', 'category'] as $field) {
            if (! isset($attributes[$field])) {
                throw new InvalidArgumentException("Signal [{$field}] is required.");
            }
        }

        if (! in_array($attributes['type'], IntelligenceSignal::TYPES, true)) {
            throw new InvalidArgumentException("Invalid intelligence signal type [{$attributes['type']}].");
        }

        if (! in_array($attributes['priority'], IntelligenceSignal::PRIORITIES, true)) {
            throw new InvalidArgumentException("Invalid intelligence signal priority [{$attributes['priority']}].");
        }

        $severity = $attributes['severity'] ?? $attributes['priority'];

        if (! in_array($severity, IntelligenceSignal::SEVERITIES, true)) {
            throw new InvalidArgumentException("Invalid intelligence signal severity [{$severity}].");
        }

        if (! in_array($attributes['category'], IntelligenceSignal::CATEGORIES, true)) {
            throw new InvalidArgumentException("Invalid intelligence signal category [{$attributes['category']}].");
        }

        $status = $attributes['status'] ?? 'new';

        if (! in_array($status, IntelligenceSignal::STATUSES, true)) {
            throw new InvalidArgumentException("Invalid intelligence signal status [{$status}].");
        }
    }
}
