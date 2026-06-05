<?php

namespace App\Console\Commands;

use App\Models\Brief;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\Draft;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MigrateContentVersionsCommand extends Command
{
    protected $signature = 'pl:migrate-content-versions {--dry-run} {--limit=0}';

    protected $description = 'Backfill content_versions from existing briefs and drafts.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $query = Content::query()
            ->with(['brief', 'drafts' => fn ($q) => $q->orderBy('created_at')])
            ->orderBy('created_at');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $contents = $query->get();
        $this->info('Contents to process: ' . $contents->count());

        if ($dryRun) {
            return self::SUCCESS;
        }

        $processed = 0;
        foreach ($contents as $content) {
            DB::transaction(function () use ($content): void {
                $briefVersion = ContentVersion::query()
                    ->where('content_id', $content->id)
                    ->where('type', 'brief')
                    ->latest('created_at')
                    ->first();

                if (! $briefVersion) {
                    $brief = $content->brief ?: Brief::query()
                        ->where('content_id', $content->id)
                        ->latest('created_at')
                        ->first();

                    if ($brief) {
                        $briefVersion = ContentVersion::query()->create([
                            'id' => (string) Str::uuid(),
                            'content_id' => $content->id,
                            'type' => 'brief',
                            'body' => json_encode([
                                'title' => $brief->title,
                                'language' => $brief->language,
                                'intent' => $brief->intent,
                                'primary_keyword' => $brief->primary_keyword,
                                'audience' => $brief->audience,
                                'output_type' => $brief->output_type,
                                'notes' => $brief->notes,
                                'client_refs' => $brief->client_refs,
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'meta' => ['brief_id' => $brief->id],
                            'source' => 'wp',
                        ]);
                    }
                }

                $parentVersionId = $briefVersion?->id;
                $drafts = $content->drafts->sortBy('created_at')->values();
                foreach ($drafts as $index => $draft) {
                    $draftBody = trim((string) ($draft->content_html ?? ''));
                    $existing = ContentVersion::query()
                        ->where('content_id', $content->id)
                        ->where('meta->draft_id', $draft->id)
                        ->first();

                    if ($existing) {
                        if (trim((string) ($existing->body ?? '')) === '' && $draftBody !== '') {
                            $existingMeta = (array) ($existing->meta ?? []);
                            if (! array_key_exists('draft_meta', $existingMeta)) {
                                $existingMeta['draft_meta'] = $draft->meta;
                            }

                            $existing->update([
                                'body' => $draftBody,
                                'meta' => $existingMeta,
                            ]);
                        }

                        $parentVersionId = $existing->id;
                        continue;
                    }

                    $type = $index === 0 ? 'draft' : 'revision';

                    $version = ContentVersion::query()->create([
                        'id' => (string) Str::uuid(),
                        'content_id' => $content->id,
                        'type' => $type,
                        'parent_version_id' => $parentVersionId,
                        'body' => $draftBody,
                        'meta' => [
                            'draft_id' => $draft->id,
                            'draft_meta' => $draft->meta,
                        ],
                        'source' => 'pl',
                    ]);

                    $parentVersionId = $version->id;
                }

                if (! $content->current_version_id) {
                    $latestDraftVersion = ContentVersion::query()
                        ->where('content_id', $content->id)
                        ->whereIn('type', ['draft', 'revision'])
                        ->latest('created_at')
                        ->first();

                    $content->update([
                        'current_version_id' => $latestDraftVersion?->id,
                        'status' => $latestDraftVersion ? 'draft' : 'brief',
                    ]);
                }
            });

            $processed++;
        }

        $this->info('Converted contents: ' . $processed);

        return self::SUCCESS;
    }
}
