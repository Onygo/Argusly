<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\Draft;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RepairOutdatedPublicationsCommand extends Command
{
    protected $signature = 'content:repair-outdated-publications
        {--dry-run : Preview repairs without persisting changes}
        {--site= : Restrict the repair to a client site id}
        {--content= : Restrict the repair to a content id}';

    protected $description = 'Repair stale translation outdated baselines from publication and draft timestamps.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rows = [];
        $repaired = 0;
        $recoverable = 0;
        $scanned = 0;

        $query = Content::query()
            ->with([
                'translationSourceContent.currentVersion',
                'drafts' => fn ($query) => $query->latest('created_at'),
                'publications' => fn ($query) => $query->latest('last_delivered_at')->latest('updated_at'),
            ])
            ->whereNotNull('translation_source_content_id')
            ->orderBy('created_at');

        if (filled($this->option('site'))) {
            $query->where('client_site_id', (string) $this->option('site'));
        }

        if (filled($this->option('content'))) {
            $query->whereKey((string) $this->option('content'));
        }

        $query->chunkById(100, function ($contents) use (&$rows, &$repaired, &$recoverable, &$scanned, $dryRun): void {
            foreach ($contents as $content) {
                $scanned++;

                $repair = $this->inspect($content);
                if ($repair['action'] === 'none') {
                    continue;
                }

                $rows[] = [
                    'content_id' => (string) $content->id,
                    'locale' => strtoupper($content->localeCode()),
                    'action' => $repair['action'],
                    'source_at' => $repair['source_at']?->format('Y-m-d H:i') ?? '-',
                    'draft_at' => $repair['draft_at']?->format('Y-m-d H:i') ?? '-',
                    'live_at' => $repair['live_at']?->format('Y-m-d H:i') ?? '-',
                ];

                if ($repair['action'] === 'recoverable') {
                    $recoverable++;
                    continue;
                }

                if ($repair['action'] === 'repair') {
                    $repaired++;

                    if (! $dryRun) {
                        $content->forceFill([
                            'translation_generated_at' => now(),
                            'translation_source_updated_at' => $repair['source_at'],
                        ])->save();
                    }
                }
            }
        });

        $this->info(sprintf('Scanned %d translation content row(s).', $scanned));

        if ($rows !== []) {
            $this->table(['content_id', 'locale', 'action', 'source_at', 'draft_at', 'live_at'], $rows);
        }

        $this->line(sprintf(
            'Outdated publications: repaired=%d recoverable=%d',
            $repaired,
            $recoverable,
        ));

        if ($dryRun) {
            $this->warn('Dry run only. No changes were persisted.');
        } else {
            $this->info('Outdated publication repair completed.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{action:'none'|'repair'|'recoverable',source_at:?Carbon,draft_at:?Carbon,live_at:?Carbon}
     */
    private function inspect(Content $content): array
    {
        $sourceAt = $this->latestSourceTimestamp($content);
        $draftAt = $this->latestDraftTimestamp($content);
        $liveAt = $this->latestLiveTimestamp($content);

        if (! $content->isTranslationOutdated()) {
            return [
                'action' => 'none',
                'source_at' => $sourceAt,
                'draft_at' => $draftAt,
                'live_at' => $liveAt,
            ];
        }

        if ($liveAt instanceof Carbon
            && $sourceAt instanceof Carbon
            && $liveAt->gte($sourceAt)
            && (! $draftAt instanceof Carbon || $liveAt->gte($draftAt))
        ) {
            return [
                'action' => 'repair',
                'source_at' => $sourceAt,
                'draft_at' => $draftAt,
                'live_at' => $liveAt,
            ];
        }

        return [
            'action' => 'recoverable',
            'source_at' => $sourceAt,
            'draft_at' => $draftAt,
            'live_at' => $liveAt,
        ];
    }

    private function latestSourceTimestamp(Content $content): ?Carbon
    {
        $source = $content->translationSourceContent;
        if (! $source instanceof Content) {
            return null;
        }

        return collect([
            $source->currentVersion?->updated_at,
            $source->currentVersion?->created_at,
            $source->updated_at,
        ])->filter()
            ->sortDesc()
            ->first();
    }

    private function latestDraftTimestamp(Content $content): ?Carbon
    {
        $draft = $content->relationLoaded('drafts')
            ? $content->drafts->first()
            : $content->drafts()->latest('created_at')->first();

        if (! $draft instanceof Draft) {
            return null;
        }

        return $draft->updated_at ?? $draft->created_at;
    }

    private function latestLiveTimestamp(Content $content): ?Carbon
    {
        $publication = ($content->relationLoaded('publications') ? $content->publications : $content->publications()->get())
            ->first(function (ContentPublication $publication): bool {
                return $publication->deliveryStatusEnum()->isSuccess();
            });

        return $publication?->last_delivered_at
            ?? $content->first_published_at;
    }
}
