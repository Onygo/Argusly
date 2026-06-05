<?php

namespace App\Jobs\LinkIntelligence;

use App\Models\Draft;
use App\Support\FeatureFlags;
use App\Services\LinkIntelligence\BuildArticleSignalsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class BuildArticleSignalsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $articleId,
    ) {}

    public function uniqueId(): string
    {
        return 'build-article-signals:' . $this->articleId;
    }

    public function handle(BuildArticleSignalsService $service, FeatureFlags $features): void
    {
        if (! $features->isEnabled('link_intelligence_jobs')) {
            return;
        }

        $lock = Cache::lock('build-article-signals-lock:' . $this->articleId, 120);

        if (! $lock->get()) {
            return;
        }

        try {
            $article = Draft::query()->with('clientSite.workspace')->find($this->articleId);
            if (! $article || ! $article->content_html) {
                return;
            }

            $service->handle($article);
        } finally {
            optional($lock)->release();
        }
    }
}
