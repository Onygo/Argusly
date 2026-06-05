<?php

namespace App\Observers;

use App\Models\StructuredAnswerBlock;
use App\Services\Aeo\AeoScoreService;
use App\Services\Content\ContentCacheInvalidationService;
use App\Services\Markdown\MarkdownArtifactService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StructuredAnswerBlockObserver
{
    public function saving(StructuredAnswerBlock $block): void
    {
        if (! filled($block->id)) {
            $block->id = (string) Str::uuid();
        }

        $block->question = trim((string) $block->question);
        $block->answer = trim((string) $block->answer);
        $block->entities = collect((array) $block->entities)
            ->map(fn (mixed $entity): string => trim((string) $entity))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $block->platforms = collect((array) $block->platforms)
            ->map(fn (mixed $platform): string => trim((string) $platform))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function saved(StructuredAnswerBlock $block): void
    {
        $this->recalculateContent($block);
    }

    public function deleted(StructuredAnswerBlock $block): void
    {
        $this->recalculateContent($block);
    }

    private function recalculateContent(StructuredAnswerBlock $block): void
    {
        $dispatch = function () use ($block): void {
            $content = $block->content()->with(['currentRevision', 'currentVersion', 'answerBlocks'])->first();

            if ($content) {
                app(MarkdownArtifactService::class)->markStaleForContent($content);
                app(AeoScoreService::class)->recalculate($content);

                if ((string) $content->type === 'article') {
                    app(ContentCacheInvalidationService::class)->invalidateContent($content, 'answer_blocks.changed');
                }
            }
        };

        if (app()->runningUnitTests()) {
            $dispatch();

            return;
        }

        DB::afterCommit($dispatch);
    }
}
