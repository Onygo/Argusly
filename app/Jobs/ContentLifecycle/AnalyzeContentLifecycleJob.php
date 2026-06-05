<?php

namespace App\Jobs\ContentLifecycle;

use App\Models\Content;
use App\Models\Workspace;
use App\Services\ContentLifecycle\ContentLifecycleDecayEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AnalyzeContentLifecycleJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public int $uniqueFor = 300;

    /**
     * @param  list<string>  $contentIds
     */
    public function __construct(
        public readonly ?string $workspaceId = null,
        public readonly array $contentIds = [],
        public readonly int $limit = 500,
    ) {
        $this->onQueue('intelligence');
    }

    public function uniqueId(): string
    {
        if ($this->contentIds !== []) {
            return 'content-lifecycle:contents:'.md5(implode('|', $this->contentIds));
        }

        return 'content-lifecycle:workspace:'.($this->workspaceId ?: 'all').':'.$this->limit;
    }

    public function handle(ContentLifecycleDecayEngine $engine): void
    {
        if ($this->contentIds !== []) {
            Content::query()
                ->whereIn('id', $this->contentIds)
                ->whereNull('deleted_at')
                ->chunkById(100, function ($contents) use ($engine): void {
                    $contents->each(fn (Content $content) => $engine->analyze($content));
                });

            return;
        }

        if ($this->workspaceId) {
            $workspace = Workspace::query()->findOrFail($this->workspaceId);
            $engine->runForWorkspace($workspace, $this->limit);

            return;
        }

        Workspace::query()
            ->orderBy('id')
            ->select(['id'])
            ->chunkById(50, function ($workspaces) use ($engine): void {
                $workspaces->each(fn (Workspace $workspace) => $engine->runForWorkspace($workspace, $this->limit));
            });
    }
}
