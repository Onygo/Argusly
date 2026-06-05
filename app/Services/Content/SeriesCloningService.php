<?php

namespace App\Services\Content;

use App\Models\ContentSeries;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SeriesCloningService
{
    public function __construct(
        private readonly ContentSeriesArticleSyncService $seriesArticleSyncService,
    ) {
    }

    public function cloneAsDraft(ContentSeries $source, int $actorUserId): ContentSeries
    {
        $cloned = DB::transaction(function () use ($source, $actorUserId): ContentSeries {
            $name = trim((string) $source->name);
            if ($name === '') {
                $name = 'Series';
            }

            return ContentSeries::query()->create([
                'id' => (string) Str::uuid(),
                'organization_id' => (int) $source->organization_id,
                'site_id' => (string) $source->site_id,
                'name' => $name . ' (Copy)',
                'main_topic' => (string) $source->main_topic,
                'primary_keyword' => (string) $source->primary_keyword,
                'supporting_keywords' => is_array($source->supporting_keywords) ? $source->supporting_keywords : [],
                'audience' => $source->audience ? (string) $source->audience : null,
                'tone' => $source->tone ? (string) $source->tone : null,
                'funnel_stage' => $source->funnel_stage ? (string) $source->funnel_stage : null,
                'articles_count' => (int) $source->articles_count,
                'status' => ContentSeries::STATUS_DRAFT,
                'is_locked' => false,
                'strategy_json' => null,
                'publish_plan_json' => null,
                'created_by' => $actorUserId,
            ]);
        });

        $this->seriesArticleSyncService->sync($cloned);

        return $cloned;
    }
}
