<?php

namespace App\Console\Commands;

use App\Jobs\GenerateContentMarkdownJob;
use App\Jobs\RebuildContentMarkdownArtifactJob;
use App\Models\Content;
use App\Services\Markdown\MarkdownArtifactService;
use Illuminate\Console\Command;

class RebuildMarkdownArtifactsCommand extends Command
{
    protected $signature = 'publishlayer:markdown:rebuild
        {--content= : Optional content UUID}
        {--locale= : Optional locale override}
        {--queue=markdown : Queue name when async}
        {--force : Force a rebuild pass}
        {--sync : Run in-process instead of dispatching jobs}';

    protected $description = 'Rebuild or refresh locale-aware markdown render artifacts for publishable content.';

    public function handle(MarkdownArtifactService $artifacts): int
    {
        $contentId = trim((string) $this->option('content'));
        $locale = trim((string) $this->option('locale')) ?: null;
        $force = (bool) $this->option('force');
        $sync = (bool) $this->option('sync');
        $queue = (string) $this->option('queue');

        $query = Content::query()
            ->select(['id', 'created_at'])
            ->orderBy('created_at')
            ->orderBy('id');

        if ($contentId !== '') {
            $query->whereKey($contentId);
        }

        $count = 0;

        foreach ($query->cursor() as $content) {
            if ($sync) {
                $artifacts->rebuildForContentId((string) $content->id, $locale, $force);
            } else {
                GenerateContentMarkdownJob::dispatch((string) $content->id, $locale, $force)
                    ->onQueue($queue);
            }

            $count++;
        }

        $this->info($sync
            ? sprintf('Markdown artifacts rebuilt for %d content items.', $count)
            : sprintf('Queued markdown artifact rebuild for %d content items.', $count));

        return self::SUCCESS;
    }
}
