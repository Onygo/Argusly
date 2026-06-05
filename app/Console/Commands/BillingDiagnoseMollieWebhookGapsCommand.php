<?php

namespace App\Console\Commands;

use App\Mail\BillingWebhookGapsAlert;
use App\Models\CreditLedgerEntry;
use App\Models\PaymentIntent;
use App\Models\Subscription;
use App\Models\WebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class BillingDiagnoseMollieWebhookGapsCommand extends Command
{
    protected $signature = 'billing:diagnose-mollie-webhook-gaps
        {--hours=72 : Look back window in hours}
        {--limit=200 : Max paid payment intents to inspect}
        {--provider_payment_id= : Optional exact Mollie payment id}
        {--payment_intent_id= : Optional exact local payment intent id}
        {--subscription_id= : Optional exact local subscription id}
        {--notify-email= : Optional alert recipient email}
        {--alert-cooldown-minutes=120 : Suppress identical alerts for this many minutes}
        {--fail-on-issues : Return non-zero when issues are found}';

    protected $description = 'Detect paid Mollie subscription intents missing webhook processing or local activation.';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $limit = max(1, (int) $this->option('limit'));
        $providerPaymentId = trim((string) $this->option('provider_payment_id'));
        $paymentIntentId = trim((string) $this->option('payment_intent_id'));
        $subscriptionId = trim((string) $this->option('subscription_id'));

        $query = PaymentIntent::query()
            ->where('provider', 'mollie')
            ->where('billable_type', Subscription::class)
            ->where('status', 'paid')
            ->whereNotNull('provider_payment_id')
            ->with('billable')
            ->orderByDesc('paid_at')
            ->orderByDesc('updated_at');

        if ($providerPaymentId !== '') {
            $query->where('provider_payment_id', $providerPaymentId);
        } else {
            $query->where(function (Builder $builder) use ($hours): void {
                $builder
                    ->where('paid_at', '>=', now()->subHours($hours))
                    ->orWhere('updated_at', '>=', now()->subHours($hours));
            });
        }

        if ($paymentIntentId !== '') {
            $query->whereKey($paymentIntentId);
        }

        if ($subscriptionId !== '') {
            $query->where('billable_id', $subscriptionId);
        }

        $intents = $query->limit($limit)->get();

        if ($intents->isEmpty()) {
            $this->info('No matching paid Mollie subscription payment intents found.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($intents as $intent) {
            $providerId = (string) $intent->provider_payment_id;
            $subscription = $intent->billable instanceof Subscription
                ? $intent->billable
                : Subscription::query()->find($intent->billable_id);

            $webhookEvent = WebhookEvent::query()
                ->where('provider', 'mollie')
                ->where('provider_event_id', $providerId)
                ->latest('created_at')
                ->first();

            $allowanceCount = $subscription
                ? CreditLedgerEntry::query()
                    ->where('source_type', Subscription::class)
                    ->where('source_id', (string) $subscription->id)
                    ->where('type', 'allowance')
                    ->count()
                : 0;

            $issueFlags = [];
            if (! $webhookEvent) {
                $issueFlags[] = 'missing_webhook_event';
            }
            if ($subscription && ! in_array((string) $subscription->status, ['active', 'trialing'], true)) {
                $issueFlags[] = 'subscription_not_active';
            }
            if ($subscription && $allowanceCount === 0) {
                $issueFlags[] = 'no_allowance_credits';
            }

            $rows[] = [
                'payment_intent_id' => (string) $intent->id,
                'provider_payment_id' => $providerId,
                'subscription_id' => (string) ($subscription?->id ?? ''),
                'organization_id' => (string) ($subscription?->organization_id ?? ''),
                'plan_id' => (string) ($subscription?->plan_id ?? ''),
                'subscription_status' => (string) ($subscription?->status ?? 'missing'),
                'allowance_entries' => $allowanceCount,
                'webhook_event' => $webhookEvent ? 'yes' : 'no',
                'issues' => implode(',', $issueFlags),
            ];
        }

        $issueRows = array_values(array_filter($rows, fn (array $row): bool => $row['issues'] !== ''));

        $this->line(sprintf(
            'Checked %d paid Mollie subscription intent(s). Problematic: %d.',
            count($rows),
            count($issueRows)
        ));

        $this->table([
            'payment_intent_id',
            'provider_payment_id',
            'subscription_id',
            'organization_id',
            'plan_id',
            'subscription_status',
            'allowance_entries',
            'webhook_event',
            'issues',
        ], $rows);

        if (count($issueRows) === 0) {
            $this->info('No webhook/activation gaps detected.');

            return self::SUCCESS;
        }

        Log::warning('billing.webhook_activation_gaps_detected', [
            'checked_count' => count($rows),
            'issue_count' => count($issueRows),
            'issue_rows' => array_slice($issueRows, 0, 25),
        ]);

        $this->sendIssueAlertIfNeeded($rows, $issueRows);

        $this->warn('Detected webhook/activation gaps.');

        if ((bool) $this->option('fail-on-issues')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,array<string,mixed>> $issueRows
     */
    private function sendIssueAlertIfNeeded(array $rows, array $issueRows): void
    {
        $recipient = trim((string) $this->option('notify-email'));
        if ($recipient === '') {
            return;
        }

        $cooldownMinutes = max(0, (int) $this->option('alert-cooldown-minutes'));
        $signaturePayload = array_map(
            fn (array $row): array => [
                'payment_intent_id' => (string) ($row['payment_intent_id'] ?? ''),
                'provider_payment_id' => (string) ($row['provider_payment_id'] ?? ''),
                'subscription_id' => (string) ($row['subscription_id'] ?? ''),
                'issues' => (string) ($row['issues'] ?? ''),
            ],
            $issueRows
        );
        $signature = sha1(json_encode($signaturePayload));

        if ($cooldownMinutes > 0) {
            $cacheKey = 'billing:webhook-gap-alert:' . $signature;
            $wasAdded = Cache::add($cacheKey, '1', now()->addMinutes($cooldownMinutes));

            if (! $wasAdded) {
                $this->line(sprintf(
                    'Alert suppressed due to cooldown (%d min) for identical issue set.',
                    $cooldownMinutes
                ));

                return;
            }
        }

        Mail::to($recipient)->send(new BillingWebhookGapsAlert(
            checkedCount: count($rows),
            issueRows: $issueRows
        ));

        $this->line('Issue alert sent to ' . $recipient . '.');
    }
}
