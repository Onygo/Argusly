<?php

namespace App\Jobs;

use App\Models\NewsletterSend;
use App\Services\NewsletterSendingService;
use App\Services\Signals\SignalManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendNewsletterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $newsletterSendId) {}

    public function handle(NewsletterSendingService $sending): void
    {
        $send = NewsletterSend::query()->findOrFail($this->newsletterSendId);

        $sending->process($send);
    }

    public function failed(Throwable $exception): void
    {
        $send = NewsletterSend::query()->find($this->newsletterSendId);

        if (! $send) {
            return;
        }

        $send->forceFill([
            'status' => 'failed',
            'completed_at' => now(),
            'error_message' => $exception->getMessage(),
        ])->save();

        app(SignalManager::class)->record($send->account, [
            'source' => 'newsletter_sending',
            'type' => 'publishing_failed',
            'category' => 'system',
            'priority' => 'high',
            'dedupe_key' => "newsletter-send-job-failed:{$send->id}",
            'title' => "Newsletter send job failed: {$send->newsletter->title}",
            'summary' => $exception->getMessage(),
            'impact_score' => 82,
            'confidence_score' => 98,
            'status' => 'new',
            'recommended_action' => 'Review the send job error and retry after resolving the underlying issue.',
            'payload' => [
                'newsletter_send_id' => $send->id,
                'newsletter_id' => $send->newsletter_id,
            ],
        ], $send->brand, generateRecommendations: false);
    }
}
