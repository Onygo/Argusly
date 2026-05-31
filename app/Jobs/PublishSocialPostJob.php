<?php

namespace App\Jobs;

use App\Models\SocialPost;
use App\Services\SocialPublishing\SocialPublishingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PublishSocialPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $socialPostId) {}

    public function handle(SocialPublishingService $publishing): void
    {
        $publishing->process(SocialPost::query()->findOrFail($this->socialPostId));
    }

    public function failed(Throwable $exception): void
    {
        $post = SocialPost::query()->find($this->socialPostId);

        if (! $post) {
            return;
        }

        app(SocialPublishingService::class)->fail($post, $exception->getMessage());
    }
}
