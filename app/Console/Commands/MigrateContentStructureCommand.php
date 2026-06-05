<?php

namespace App\Console\Commands;

use App\Models\Brief;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\ContentSeo;
use App\Models\Draft;
use App\Services\Content\ContentLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MigrateContentStructureCommand extends Command
{
    protected $signature = 'pl:migrate-content-structure {--dry-run} {--limit=0}';

    protected $description = 'Backfill Content model structure from existing drafts and briefs.';

    public function handle(ContentLifecycleService $contentLifecycleService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $query = Draft::query()->with('clientSite.workspace', 'brief')->orderBy('created_at');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $drafts = $query->get();

        $this->info('Drafts to process: ' . $drafts->count());

        if ($dryRun) {
            return self::SUCCESS;
        }

        $processed = 0;

        foreach ($drafts as $draft) {
            if (! $draft->clientSite?->workspace_id) {
                continue;
            }

            DB::transaction(function () use ($draft, $contentLifecycleService): void {
                $externalId = (string) data_get($draft->brief?->client_refs, 'wp_brief_id', '');

                $content = Content::query()->firstOrCreate(
                    [
                        'workspace_id' => $draft->clientSite->workspace_id,
                        'external_id' => $externalId !== '' ? $externalId : null,
                        'title' => $draft->title,
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'type' => $contentLifecycleService->mapOutputTypeToContentType((string) ($draft->output_type ?? 'article')),
                        'status' => 'draft',
                        'source' => 'wp',
                        'delivery_status' => (string) ($draft->delivery_status ?? 'pending'),
                        'generation_mode' => 'balanced',
                    ],
                );

                if (! $draft->content_id) {
                    $draft->update(['content_id' => $content->id]);
                }

                if ($draft->brief && ! $draft->brief->content_id) {
                    $draft->brief->update(['content_id' => $content->id]);
                }

                $hasRevision = ContentRevision::query()
                    ->where('content_id', $content->id)
                    ->where('draft_id', $draft->id)
                    ->exists();

                if (! $hasRevision) {
                    $contentLifecycleService->ensureRevisionFromDraft($draft->fresh());
                }

                $brief = $draft->brief;
                if ($brief) {
                    ContentSeo::query()->updateOrCreate(
                        ['content_id' => $content->id],
                        [
                            'id' => (string) (optional($content->seo)->id ?? Str::uuid()),
                            'meta_title' => $content->title,
                            'meta_description' => (string) data_get($draft->meta, 'meta.description', ''),
                            'primary_keyword' => (string) ($brief->primary_keyword ?? data_get($draft->meta, 'primary_keyword', '')),
                            'secondary_keywords' => (array) data_get($draft->meta, 'secondary_keywords', []),
                            'schema_enabled' => false,
                            'toc_enabled' => false,
                        ],
                    );
                }
            });

            $processed++;
            if ($processed % 50 === 0) {
                $this->line('Processed: ' . $processed);
            }
        }

        $this->info('Done. Processed drafts: ' . $processed);

        return self::SUCCESS;
    }
}
