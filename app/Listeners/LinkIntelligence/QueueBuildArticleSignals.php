<?php

namespace App\Listeners\LinkIntelligence;

use App\Events\LinkIntelligence\ArticleSignalsRequested;
use App\Jobs\LinkIntelligence\BuildArticleSignalsJob;
use App\Support\FeatureFlags;

class QueueBuildArticleSignals
{
    public function __construct(private readonly FeatureFlags $features)
    {
    }

    public function handle(ArticleSignalsRequested $event): void
    {
        if (! $this->features->isEnabled('link_intelligence_jobs')) {
            return;
        }

        BuildArticleSignalsJob::dispatch($event->articleId)->onQueue('default');
    }
}
