<?php

namespace App\Services;

use App\Jobs\SendNewsletterJob;
use App\Models\Account;
use App\Models\Audience;
use App\Models\AudienceMember;
use App\Models\Brand;
use App\Models\EmailProvider;
use App\Models\Newsletter;
use App\Models\NewsletterSend;
use App\Models\NewsletterSendRecipient;
use App\Models\Segment;
use App\Models\User;
use App\Services\Signals\SignalManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class NewsletterSendingService
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly CreditService $credits,
        private readonly EmailProviderManager $providers,
        private readonly DomainEventService $events,
        private readonly SignalManager $signals,
    ) {}

    /**
     * @param  array{audience_id?: int|string|null, segment_id?: int|string|null, email_provider_id?: int|string|null, dispatch?: bool|null}  $attributes
     */
    public function queue(Newsletter $newsletter, User $user, array $attributes = []): NewsletterSend
    {
        if (! $this->approvals->hasApproved($newsletter)) {
            throw new InvalidArgumentException('Newsletter must be approved before sending.');
        }

        $audience = $this->audience($newsletter, $attributes['audience_id'] ?? null);
        $segment = $this->segment($newsletter, $attributes['segment_id'] ?? null);
        $provider = $this->provider($newsletter, $attributes['email_provider_id'] ?? $newsletter->email_provider_id);
        $members = $this->recipients($newsletter, $audience, $segment);

        if ($members->isEmpty()) {
            throw new InvalidArgumentException('Newsletter send requires at least one active audience member.');
        }

        $send = NewsletterSend::query()->create([
            'account_id' => $newsletter->account_id,
            'brand_id' => $newsletter->brand_id,
            'newsletter_id' => $newsletter->id,
            'audience_id' => $segment?->audience_id ?? $audience?->id,
            'segment_id' => $segment?->id,
            'email_provider_id' => $provider->id,
            'status' => 'queued',
            'total_recipients' => $members->count(),
        ]);

        $members->each(function (AudienceMember $member) use ($send): void {
            NewsletterSendRecipient::query()->create([
                'newsletter_send_id' => $send->id,
                'audience_member_id' => $member->id,
                'email' => $member->email,
                'status' => 'queued',
                'metadata' => [
                    'first_name' => $member->first_name,
                    'last_name' => $member->last_name,
                ],
            ]);
        });

        $this->events->recordForSubject('NewsletterSendQueued', $send, $user, [
            'newsletter_id' => $newsletter->id,
            'audience_id' => $audience?->id,
            'segment_id' => $segment?->id,
            'email_provider_id' => $provider->id,
            'total_recipients' => $send->total_recipients,
        ]);

        if ($attributes['dispatch'] ?? true) {
            SendNewsletterJob::dispatch($send->id);
        }

        return $send->refresh();
    }

    public function process(NewsletterSend $send): NewsletterSend
    {
        if (! in_array($send->status, ['queued', 'sending'], true)) {
            return $send;
        }

        $send->loadMissing(['newsletter.sections.contentAsset', 'emailProvider', 'account', 'brand']);

        $send->forceFill([
            'status' => 'sending',
            'started_at' => $send->started_at ?? now(),
            'error_message' => null,
        ])->save();

        $this->events->recordForSubject('NewsletterSendStarted', $send->refresh(), null, [
            'newsletter_id' => $send->newsletter_id,
            'total_recipients' => $send->total_recipients,
        ]);

        foreach ($send->recipients()->where('status', 'queued')->orderBy('id')->get() as $recipient) {
            $this->sendRecipient($send, $recipient);
        }

        $sent = $send->recipients()->where('status', 'sent')->count();
        $failed = $send->recipients()->where('status', 'failed')->count();
        $status = $failed > 0 ? 'failed' : 'sent';

        $send->forceFill([
            'status' => $status,
            'sent_count' => $sent,
            'failed_count' => $failed,
            'completed_at' => now(),
            'error_message' => $failed > 0 ? "{$failed} newsletter recipients failed." : null,
        ])->save();

        $this->events->recordForSubject(
            $status === 'sent' ? 'NewsletterSendCompleted' : 'NewsletterSendFailed',
            $send->refresh(),
            null,
            [
                'newsletter_id' => $send->newsletter_id,
                'sent_count' => $sent,
                'failed_count' => $failed,
                'total_recipients' => $send->total_recipients,
                'error_message' => $send->error_message,
            ],
        );

        if ($status === 'failed') {
            $this->recordFailureSignal($send);
        }

        return $send->refresh();
    }

    private function sendRecipient(NewsletterSend $send, NewsletterSendRecipient $recipient): void
    {
        $recipient->forceFill(['status' => 'sending'])->save();

        try {
            $this->credits->consumeForAccount(
                $send->account,
                'newsletter_recipient',
                'Newsletter recipient send.',
                $send,
                [
                    'newsletter_send_id' => $send->id,
                    'newsletter_id' => $send->newsletter_id,
                    'recipient_email' => $recipient->email,
                ],
            );

            $result = $this->providers->sendNewsletterEmail($send->emailProvider, $recipient->email, $this->payload($send));

            if (! $result['ok']) {
                $recipient->forceFill([
                    'status' => 'failed',
                    'error_message' => $result['error'] ?? 'Fake email provider failed.',
                    'metadata' => [
                        ...($recipient->metadata ?? []),
                        'provider' => $result['provider'],
                    ],
                ])->save();

                return;
            }

            $recipient->forceFill([
                'status' => 'sent',
                'sent_at' => now(),
                'error_message' => null,
                'metadata' => [
                    ...($recipient->metadata ?? []),
                    'provider' => $result['provider'],
                    'message_id' => $result['message_id'] ?? null,
                ],
            ])->save();
        } catch (\Throwable $exception) {
            $recipient->forceFill([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ])->save();
        }
    }

    /**
     * @return array{subject: string, text: string, metadata: array<string, mixed>}
     */
    private function payload(NewsletterSend $send): array
    {
        $newsletter = $send->newsletter;

        return [
            'subject' => $newsletter->subject ?: $newsletter->title,
            'text' => $newsletter->sections
                ->map(fn ($section) => trim(($section->title ?: $section->contentAsset?->title ?: '')."\n".($section->body ?: $section->contentAsset?->excerpt ?: '')))
                ->filter()
                ->implode("\n\n"),
            'metadata' => [
                'newsletter_id' => $newsletter->id,
                'newsletter_send_id' => $send->id,
                'language' => $newsletter->language,
            ],
        ];
    }

    private function recordFailureSignal(NewsletterSend $send): void
    {
        $this->signals->record($send->account, [
            'source' => 'newsletter_sending',
            'type' => 'publishing_failed',
            'category' => 'system',
            'priority' => 'high',
            'dedupe_key' => "newsletter-send-failed:{$send->id}",
            'title' => "Newsletter send failed: {$send->newsletter->title}",
            'summary' => $send->error_message ?: 'One or more newsletter recipients failed.',
            'impact_score' => 78,
            'confidence_score' => 98,
            'status' => 'new',
            'recommended_action' => 'Review the email provider settings, failed recipients and available credits before retrying.',
            'payload' => [
                'newsletter_send_id' => $send->id,
                'newsletter_id' => $send->newsletter_id,
                'failed_count' => $send->failed_count,
                'sent_count' => $send->sent_count,
            ],
        ], $send->brand, generateRecommendations: false);
    }

    private function audience(Newsletter $newsletter, mixed $audienceId): ?Audience
    {
        if ($audienceId === null || $audienceId === '') {
            return null;
        }

        return Audience::query()
            ->where('account_id', $newsletter->account_id)
            ->where(fn (Builder $query) => $query
                ->whereNull('brand_id')
                ->orWhere('brand_id', $newsletter->brand_id))
            ->findOrFail((int) $audienceId);
    }

    private function segment(Newsletter $newsletter, mixed $segmentId): ?Segment
    {
        if ($segmentId === null || $segmentId === '') {
            return null;
        }

        return Segment::query()
            ->where('account_id', $newsletter->account_id)
            ->where(fn (Builder $query) => $query
                ->whereNull('brand_id')
                ->orWhere('brand_id', $newsletter->brand_id))
            ->findOrFail((int) $segmentId);
    }

    private function provider(Newsletter $newsletter, mixed $providerId): EmailProvider
    {
        if ($providerId === null || $providerId === '') {
            throw new InvalidArgumentException('Newsletter send requires an email provider.');
        }

        return EmailProvider::query()
            ->where('account_id', $newsletter->account_id)
            ->where(fn (Builder $query) => $query
                ->whereNull('brand_id')
                ->orWhere('brand_id', $newsletter->brand_id))
            ->findOrFail((int) $providerId);
    }

    /**
     * @return Collection<int, AudienceMember>
     */
    private function recipients(Newsletter $newsletter, ?Audience $audience, ?Segment $segment): Collection
    {
        $audienceId = $segment?->audience_id ?? $audience?->id;

        if ($audienceId === null) {
            throw new InvalidArgumentException('Newsletter send requires an audience or segment with an audience.');
        }

        return AudienceMember::query()
            ->where('account_id', $newsletter->account_id)
            ->where('audience_id', $audienceId)
            ->where('status', 'active')
            ->orderBy('id')
            ->get()
            ->unique('email')
            ->values();
    }
}
