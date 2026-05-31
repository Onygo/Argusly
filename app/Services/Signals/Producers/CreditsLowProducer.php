<?php

namespace App\Services\Signals\Producers;

use App\Contracts\Signals\SignalProducer;
use App\Models\CreditTransaction;
use App\Models\IntelligenceSignal;
use App\Services\Signals\SignalManager;

class CreditsLowProducer implements SignalProducer
{
    private const LOW_CREDIT_THRESHOLD = 100;

    public function supports(object $event): bool
    {
        return $event instanceof CreditTransaction
            && $event->balance_after <= self::LOW_CREDIT_THRESHOLD;
    }

    public function produce(object $event): ?IntelligenceSignal
    {
        /** @var CreditTransaction $event */
        $priority = $event->balance_after <= 0 ? 'critical' : 'high';

        return app(SignalManager::class)->record($event->account, [
            'source' => 'billing',
            'type' => 'credits_low',
            'category' => 'billing',
            'priority' => $priority,
            'dedupe_key' => "credits-low:{$event->account_id}",
            'title' => 'Credits are running low',
            'summary' => "The account credit balance is now {$event->balance_after}. Generation, audits and publishing may be blocked soon.",
            'impact_score' => $event->balance_after <= 0 ? 95 : 75,
            'confidence_score' => 99,
            'status' => 'new',
            'recommended_action' => 'Review credit usage and top up before critical workflows are blocked.',
            'payload' => [
                'credit_transaction_id' => $event->id,
                'balance_after' => $event->balance_after,
                'amount' => $event->amount,
                'type' => $event->type,
            ],
        ]);
    }
}
