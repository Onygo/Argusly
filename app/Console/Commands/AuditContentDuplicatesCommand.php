<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Services\Content\ContentDeduplicationService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuditContentDuplicatesCommand extends Command
{
    protected $signature = 'content:audit-duplicates
        {--apply : Mark duplicate rows without deleting content}
        {--limit=500 : Maximum duplicate groups to inspect}';

    protected $description = 'Audit generated content duplicates and optionally mark duplicate_of_content_id safely.';

    public function handle(ContentDeduplicationService $deduplicationService): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $groups = $this->duplicateGroups($limit);

        if ($groups->isEmpty()) {
            $this->info('No generated content duplicate groups found.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $marked = 0;

        foreach ($groups as $group) {
            $contents = Content::query()
                ->whereIn('id', $group['ids'])
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            $canonical = $contents->first();
            if (! $canonical instanceof Content) {
                continue;
            }

            $duplicates = $contents->slice(1);
            $this->line(sprintf(
                '%s duplicate(s): canonical=%s key=%s title=%s',
                $duplicates->count(),
                $canonical->id,
                $group['key'],
                Str::limit((string) $canonical->title, 90)
            ));

            if (! $apply) {
                continue;
            }

            DB::transaction(function () use ($canonical, $duplicates, $deduplicationService, &$marked): void {
                if (! $canonical->dedupe_fingerprint) {
                    $canonical->forceFill([
                        'dedupe_fingerprint' => $deduplicationService->fingerprint($deduplicationService->scopeFromPayload($canonical->getAttributes())),
                        'duplicate_checked_at' => now(),
                    ])->save();
                }

                foreach ($duplicates as $duplicate) {
                    $duplicate->forceFill([
                        'duplicate_checked_at' => now(),
                        'duplicate_of_content_id' => (string) $canonical->id,
                    ])->save();

                    $marked++;
                }
            });
        }

        if ($apply) {
            $this->info("Marked {$marked} duplicate content row(s). No content was deleted.");
        } else {
            $this->comment('Run with --apply to mark duplicate_of_content_id on non-canonical rows.');
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, array{key:string, ids:array<int,string>}>
     */
    private function duplicateGroups(int $limit): Collection
    {
        return Content::query()
            ->whereIn('source', ['automation', 'api', 'system'])
            ->whereNull('duplicate_of_content_id')
            ->get([
                'id',
                'workspace_id',
                'client_site_id',
                'automation_id',
                'language',
                'type',
                'external_key',
                'title',
                'primary_keyword',
                'created_at',
            ])
            ->groupBy(fn (Content $content): string => implode('|', [
                (string) $content->workspace_id,
                (string) ($content->client_site_id ?? ''),
                (string) ($content->automation_id ?? ''),
                strtolower((string) ($content->language?->value ?? $content->language ?? '')),
                strtolower((string) ($content->type ?? '')),
                strtolower(trim((string) ($content->external_key ?: $content->primary_keyword ?: $content->title))),
            ]))
            ->filter(fn (Collection $contents): bool => $contents->count() > 1)
            ->take($limit)
            ->map(fn (Collection $contents, string $key): array => [
                'key' => $key,
                'ids' => $contents->pluck('id')->map(fn ($id): string => (string) $id)->values()->all(),
            ])
            ->values();
    }
}
