<?php

namespace App\Services\PageIntelligence\Reports;

use App\Models\Notification;
use App\Models\PageIntelligenceReport;
use App\Models\PageIntelligenceReportDelivery;
use App\Models\ScheduledPageIntelligenceBriefing;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class PageIntelligenceReportDeliveryService
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function deliverScheduledReport(PageIntelligenceReport $report, ScheduledPageIntelligenceBriefing $schedule): array
    {
        if ((string) $report->workspace_id !== (string) $schedule->workspace_id) {
            throw new InvalidArgumentException('Report and scheduled briefing must belong to the same workspace.');
        }

        $recipients = $this->recipientEmails($schedule);
        $channels = $this->channels($schedule);
        $deliveries = collect();

        if ($this->shouldDeliverInApp($channels)) {
            $internalUsers = $this->internalUsers($report, $recipients);
            foreach ($internalUsers as $user) {
                $deliveries->push($this->deliverInApp($report, $schedule, $user));
            }
        }

        if ($this->shouldRecordEmailPlaceholder($channels)) {
            foreach ($recipients as $email) {
                $deliveries->push($this->recordEmailPlaceholder($report, $schedule, $email));
            }
        }

        return [
            'status' => $this->summaryStatus($deliveries),
            'delivery_enabled' => true,
            'email_sent' => false,
            'channels' => $channels,
            'total' => $deliveries->count(),
            'delivered' => $deliveries->where('status', PageIntelligenceReportDelivery::STATUS_DELIVERED)->count(),
            'failed' => $deliveries->where('status', PageIntelligenceReportDelivery::STATUS_FAILED)->count(),
            'skipped' => $deliveries->where('status', PageIntelligenceReportDelivery::STATUS_SKIPPED)->count(),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    private function deliverInApp(PageIntelligenceReport $report, ScheduledPageIntelligenceBriefing $schedule, User $user): PageIntelligenceReportDelivery
    {
        $delivery = $this->firstOrCreateDelivery($report, $schedule, [
            'recipient_user_id' => $user->id,
            'recipient_email' => mb_strtolower((string) $user->email),
            'channel' => PageIntelligenceReportDelivery::CHANNEL_IN_APP,
        ], [
            'recipient_email' => mb_strtolower((string) $user->email),
            'metadata_json' => [
                'source' => 'scheduled_page_intelligence_briefing',
                'recipient_type' => 'internal_user',
            ],
        ]);

        if ($delivery->status === PageIntelligenceReportDelivery::STATUS_DELIVERED && filled(data_get($delivery->metadata_json, 'notification_id'))) {
            return $delivery;
        }

        try {
            $notification = $this->notifications->notifyUser(
                (int) $user->id,
                (string) $report->workspace_id,
                Notification::TYPE_SYSTEM,
                'Page Intelligence briefing ready',
                $report->title,
                [
                    'cta_label' => 'View report',
                    'cta_url' => route('app.page-intelligence.reports.show', $report),
                    'dedupe_key' => $this->dedupeKey($report, PageIntelligenceReportDelivery::CHANNEL_IN_APP, (string) $user->id),
                    'meta' => [
                        'page_intelligence_report_id' => $report->id,
                        'scheduled_page_intelligence_briefing_id' => $schedule->id,
                        'delivery_id' => $delivery->id,
                    ],
                ],
            );

            $delivery->forceFill([
                'status' => PageIntelligenceReportDelivery::STATUS_DELIVERED,
                'delivered_at' => $delivery->delivered_at ?? now(),
                'failed_at' => null,
                'error' => null,
                'metadata_json' => array_merge((array) ($delivery->metadata_json ?? []), [
                    'notification_id' => $notification->id,
                    'notification_cta_url' => $notification->cta_url,
                ]),
            ])->save();
        } catch (Throwable $exception) {
            $delivery->forceFill([
                'status' => PageIntelligenceReportDelivery::STATUS_FAILED,
                'failed_at' => now(),
                'error' => mb_substr($exception->getMessage(), 0, 4000),
            ])->save();
        }

        return $delivery->refresh();
    }

    private function recordEmailPlaceholder(PageIntelligenceReport $report, ScheduledPageIntelligenceBriefing $schedule, string $email): PageIntelligenceReportDelivery
    {
        $delivery = $this->firstOrCreateDelivery($report, $schedule, [
            'recipient_user_id' => null,
            'recipient_email' => $email,
            'channel' => PageIntelligenceReportDelivery::CHANNEL_EMAIL_PLACEHOLDER,
        ], [
            'metadata_json' => [
                'source' => 'scheduled_page_intelligence_briefing',
                'recipient_type' => 'email_placeholder',
                'requested_channel' => 'email',
                'disabled_reason' => 'email_delivery_not_implemented',
            ],
        ]);

        if ($delivery->status !== PageIntelligenceReportDelivery::STATUS_SKIPPED) {
            $delivery->forceFill([
                'status' => PageIntelligenceReportDelivery::STATUS_SKIPPED,
                'delivered_at' => null,
                'failed_at' => null,
                'error' => 'Email delivery is not implemented.',
                'metadata_json' => array_merge((array) ($delivery->metadata_json ?? []), [
                    'requested_channel' => 'email',
                    'disabled_reason' => 'email_delivery_not_implemented',
                ]),
            ])->save();
        }

        return $delivery->refresh();
    }

    /**
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $defaults
     */
    private function firstOrCreateDelivery(PageIntelligenceReport $report, ScheduledPageIntelligenceBriefing $schedule, array $identity, array $defaults): PageIntelligenceReportDelivery
    {
        return DB::transaction(function () use ($report, $schedule, $identity, $defaults): PageIntelligenceReportDelivery {
            $query = PageIntelligenceReportDelivery::query()
                ->where('report_id', $report->id)
                ->where('channel', $identity['channel']);

            if ($identity['recipient_user_id'] === null) {
                $query->whereNull('recipient_user_id');
            } else {
                $query->where('recipient_user_id', $identity['recipient_user_id']);
            }

            if ($identity['recipient_email'] === null) {
                $query->whereNull('recipient_email');
            } else {
                $query->where('recipient_email', $identity['recipient_email']);
            }

            $existing = $query->lockForUpdate()->first();
            if ($existing instanceof PageIntelligenceReportDelivery) {
                return $existing;
            }

            return PageIntelligenceReportDelivery::query()->create($identity + $defaults + [
                'report_id' => $report->id,
                'scheduled_briefing_id' => $schedule->id,
                'workspace_id' => $report->workspace_id,
                'status' => PageIntelligenceReportDelivery::STATUS_PENDING,
            ]);
        });
    }

    /**
     * @return array<int,string>
     */
    private function recipientEmails(ScheduledPageIntelligenceBriefing $schedule): array
    {
        return collect((array) ($schedule->recipients_json ?? []))
            ->map(fn (mixed $recipient): string => mb_strtolower(trim((string) $recipient)))
            ->filter(fn (string $recipient): bool => filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function channels(ScheduledPageIntelligenceBriefing $schedule): array
    {
        $channels = collect((array) ($schedule->delivery_channels_json ?? []))
            ->map(fn (mixed $channel): string => trim((string) $channel))
            ->filter()
            ->map(fn (string $channel): string => $channel === 'email' ? PageIntelligenceReportDelivery::CHANNEL_EMAIL_PLACEHOLDER : $channel)
            ->filter(fn (string $channel): bool => in_array($channel, [
                PageIntelligenceReportDelivery::CHANNEL_IN_APP,
                PageIntelligenceReportDelivery::CHANNEL_EMAIL_PLACEHOLDER,
            ], true))
            ->unique()
            ->values()
            ->all();

        return $channels === [] ? [PageIntelligenceReportDelivery::CHANNEL_IN_APP] : $channels;
    }

    /**
     * @param array<int,string> $recipientEmails
     * @return Collection<int,User>
     */
    private function internalUsers(PageIntelligenceReport $report, array $recipientEmails): Collection
    {
        if ($recipientEmails === []) {
            return collect();
        }

        return User::query()
            ->where('organization_id', $report->workspace?->organization_id)
            ->where('active', true)
            ->whereIn('email', $recipientEmails)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param array<int,string> $channels
     */
    private function shouldDeliverInApp(array $channels): bool
    {
        return in_array(PageIntelligenceReportDelivery::CHANNEL_IN_APP, $channels, true);
    }

    /**
     * @param array<int,string> $channels
     */
    private function shouldRecordEmailPlaceholder(array $channels): bool
    {
        return in_array(PageIntelligenceReportDelivery::CHANNEL_EMAIL_PLACEHOLDER, $channels, true);
    }

    private function dedupeKey(PageIntelligenceReport $report, string $channel, string $recipient): string
    {
        return implode(':', ['page-intelligence-report-delivery', $report->id, $channel, $recipient]);
    }

    /**
     * @param Collection<int,PageIntelligenceReportDelivery> $deliveries
     */
    private function summaryStatus(Collection $deliveries): string
    {
        if ($deliveries->isEmpty()) {
            return 'no_recipients';
        }

        if ($deliveries->where('status', PageIntelligenceReportDelivery::STATUS_FAILED)->isNotEmpty()) {
            return 'failed';
        }

        if ($deliveries->where('status', PageIntelligenceReportDelivery::STATUS_DELIVERED)->isNotEmpty()) {
            return 'delivered';
        }

        return 'skipped';
    }
}
