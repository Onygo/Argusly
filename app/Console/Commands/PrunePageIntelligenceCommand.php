<?php

namespace App\Console\Commands;

use App\Models\PageAlert;
use App\Models\PageContentExtraction;
use App\Models\PageGeoObservation;
use App\Models\PageSerpObservation;
use App\Models\PageSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PrunePageIntelligenceCommand extends Command
{
    protected $signature = 'page-intelligence:prune {--dry-run : Report counts without deleting records or files}';

    protected $description = 'Prune aged Page Intelligence snapshots, stored raw content, observations, and alerts.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $counts = [
            'raw_html_files' => $this->pruneRawHtml($dryRun),
            'snapshots' => $this->pruneSnapshots($dryRun),
            'serp_observations' => $this->pruneModel(PageSerpObservation::class, 'observed_at', 'serp_observation_days', $dryRun),
            'geo_observations' => $this->pruneModel(PageGeoObservation::class, 'observed_at', 'geo_observation_days', $dryRun),
            'alerts' => $this->pruneModel(PageAlert::class, 'fired_at', 'alert_days', $dryRun),
        ];

        foreach ($counts as $label => $count) {
            $this->line($label.': '.$count);
        }

        return self::SUCCESS;
    }

    private function pruneRawHtml(bool $dryRun): int
    {
        $cutoff = now()->subDays(max(1, (int) config('page_intelligence.retention.raw_html_days', 30)));
        $count = 0;

        PageSnapshot::query()
            ->whereNotNull('raw_html_path')
            ->where('fetched_at', '<', $cutoff)
            ->chunkById(100, function ($snapshots) use ($dryRun, &$count): void {
                foreach ($snapshots as $snapshot) {
                    $count++;

                    if ($dryRun) {
                        continue;
                    }

                    Storage::disk((string) config('page_intelligence.fetch.raw_html_disk', 'local'))
                        ->delete((string) $snapshot->raw_html_path);

                    $snapshot->forceFill([
                        'raw_html_path' => null,
                        'raw_html' => null,
                    ])->save();
                }
            });

        return $count;
    }

    private function pruneSnapshots(bool $dryRun): int
    {
        $cutoff = now()->subDays(max(1, (int) config('page_intelligence.retention.snapshot_days', 180)));
        $count = 0;

        PageSnapshot::query()
            ->where('fetched_at', '<', $cutoff)
            ->chunkById(100, function ($snapshots) use ($dryRun, &$count): void {
                foreach ($snapshots as $snapshot) {
                    $count++;

                    if ($dryRun) {
                        continue;
                    }

                    $this->deleteExtractionFiles((string) $snapshot->id);
                    $snapshot->delete();
                }
            });

        return $count;
    }

    /**
     * @param class-string<\Illuminate\Database\Eloquent\Model> $model
     */
    private function pruneModel(string $model, string $dateColumn, string $retentionKey, bool $dryRun): int
    {
        $cutoff = now()->subDays(max(1, (int) config('page_intelligence.retention.'.$retentionKey, 180)));
        $query = $model::query()->where($dateColumn, '<', $cutoff);
        $count = (int) $query->count();

        if (! $dryRun) {
            $query->delete();
        }

        return $count;
    }

    private function deleteExtractionFiles(string $snapshotId): void
    {
        PageContentExtraction::query()
            ->where('page_snapshot_id', $snapshotId)
            ->get()
            ->each(function (PageContentExtraction $extraction): void {
                $disk = Storage::disk((string) config('page_intelligence.storage.extracted_text_disk', 'local'));

                if ($extraction->main_text_path) {
                    $disk->delete((string) $extraction->main_text_path);
                }

                if ($extraction->main_html_path) {
                    $disk->delete((string) $extraction->main_html_path);
                }

                $metadata = (array) ($extraction->metadata_json ?? []);
                $metadata['retention'] = array_filter([
                    'status' => 'pruned',
                    'pruned_at' => now()->toIso8601String(),
                    'main_text_path' => $extraction->main_text_path,
                    'main_html_path' => $extraction->main_html_path,
                ]);

                $extraction->forceFill([
                    'main_text_path' => null,
                    'main_html_path' => null,
                    'main_text' => null,
                    'main_html' => null,
                    'metadata_json' => $metadata,
                ])->save();

                $extraction->delete();
            });
    }
}
