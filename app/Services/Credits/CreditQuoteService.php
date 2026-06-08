<?php

namespace App\Services\Credits;

use App\Models\ClientSite;
use App\Models\CreditAction;
use App\Services\CreditWalletService;

class CreditQuoteService
{
    public function __construct(
        private readonly CreditWalletService $wallets
    ) {}

    public function walletSnapshot(string $clientSiteId): array
    {
        $summary = $this->wallets->getSummary($clientSiteId);
        $site = ClientSite::query()->whereKey($clientSiteId)->first(['id', 'workspace_id']);

        return [
            'site_id' => (string) ($site?->id ?? $clientSiteId),
            'workspace_id' => (string) ($site?->workspace_id ?? ''),
            'available_credits' => (int) ($summary['available'] ?? 0),
            'reserved_credits' => (int) ($summary['reserved_cached'] ?? 0),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    public function requiredCreditsForAction(string $action, array $payload = []): int
    {
        if ($action === 'image_generate') {
            $cost = $this->resolveActionCost('content.featured_image');

            // TODO: remove config fallback when every action quote is resolved from live action rules.
            return $cost > 0 ? $cost : max(1, (int) config('argusly.ai.images.credit_cost', 6));
        }

        if ($action === 'draft_generate') {
            $outputType = strtolower(trim((string) ($payload['output_type'] ?? data_get($payload, 'brief.output_type', ''))));
            $preferredActionKey = match ($outputType) {
                'faq', 'faq_set' => 'content.faq_set',
                'outline' => 'content.outline',
                'brief' => 'content.brief',
                default => 'content.article',
            };

            $cost = $this->resolveActionCost($preferredActionKey);
            if ($cost > 0) {
                return $cost;
            }

            $fallbackContentAction = CreditAction::query()
                ->where('category', 'content')
                ->where('is_active', true)
                ->orderBy('credits_cost')
                ->first();
            if ($fallbackContentAction) {
                return (int) $fallbackContentAction->credits_cost;
            }

            // TODO: remove hardcoded fallback after quote coverage is complete.
            return 4;
        }

        return 0;
    }

    public function insufficientPayload(string $action, int $required, int $available): array
    {
        $message = sprintf(
            'Insufficient credits. Required: %d, available: %d. Buy extra credits to continue.',
            $required,
            max(0, $available)
        );

        return [
            'error' => $message,
            'message' => $message,
            'code' => 'INSUFFICIENT_CREDITS',
            'error_code' => 'INSUFFICIENT_CREDITS',
            'public_error_code' => 'CREDIT_BALANCE_LOW',
            'required' => (int) $required,
            'available' => max(0, (int) $available),
            'action' => $action,
        ];
    }

    private function resolveActionCost(string $key): int
    {
        $action = CreditAction::query()
            ->where('key', $key)
            ->where('is_active', true)
            ->first();

        return $action ? (int) $action->credits_cost : 0;
    }
}
