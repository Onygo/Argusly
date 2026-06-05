<?php

namespace App\Console\Commands;

use App\Models\Content;
use Illuminate\Console\Command;

class DebugAnswerBlocksCommand extends Command
{
    protected $signature = 'content:debug-answer-blocks {contentId}';

    protected $description = 'Inspect the latest structured answer generation attempt for a content item.';

    public function handle(): int
    {
        $content = Content::query()
            ->with(['answerBlocks', 'currentRevision', 'currentVersion'])
            ->find((string) $this->argument('contentId'));

        if (! $content) {
            $this->error('Content not found.');

            return self::FAILURE;
        }

        $meta = is_array($content->answer_block_generation_meta) ? $content->answer_block_generation_meta : [];

        $this->table(['Field', 'Value'], [
            ['content id', (string) $content->id],
            ['title', (string) $content->title],
            ['locale', $content->localeCode()],
            ['status', (string) $content->status],
            ['latest generation attempt', (string) ($content->answer_block_generation_completed_at?->toDateTimeString() ?? $content->answer_block_generation_started_at?->toDateTimeString() ?? 'n/a')],
            ['raw count', (string) data_get($meta, 'raw_block_count', 0)],
            ['parsed count', (string) data_get($meta, 'parsed_block_count', 0)],
            ['accepted count', (string) data_get($meta, 'accepted_block_count', 0)],
            ['raw response length', (string) data_get($meta, 'raw_response_length', 0)],
            ['saved answer block ids', implode(', ', (array) data_get($meta, 'saved_block_ids', [])) ?: 'none'],
            ['relation used by UI', 'answerBlocks'],
            ['validation rejection reasons', json_encode((array) data_get($meta, 'rejection_reasons', []), JSON_UNESCAPED_SLASHES)],
        ]);

        if ($content->answerBlocks->isNotEmpty()) {
            $this->newLine();
            $this->info('Current answer blocks');
            $this->table(
                ['ID', 'Question', 'Platforms', 'Order'],
                $content->answerBlocks->map(fn ($block): array => [
                    (string) $block->id,
                    (string) $block->question,
                    implode(', ', (array) ($block->platforms ?? [])),
                    (string) $block->order,
                ])->all()
            );
        }

        return self::SUCCESS;
    }
}
