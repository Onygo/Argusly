<?php

namespace App\Console\Commands;

use App\Models\ContentPublication;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RepairPublicationIntegrityCommand extends Command
{
    protected $signature = 'content:repair-publication-integrity {--fix : Demote duplicate active Laravel publications}';

    protected $description = 'Report or repair duplicate active Laravel content publications.';

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');
        $groups = $this->duplicateActiveGroups();

        if ($groups->isEmpty()) {
            $this->info('No duplicate active Laravel publications found.');

            return self::SUCCESS;
        }

        $repaired = 0;

        foreach ($groups as $key => $publications) {
            /** @var Collection<int,ContentPublication> $publications */
            $canonical = $publications->first();
            $duplicates = $publications->slice(1);

            $this->line(sprintf(
                '%s: active=%d canonical=%s duplicates=%s',
                (string) $key,
                $publications->count(),
                (string) $canonical?->id,
                $duplicates->pluck('id')->map(fn ($id): string => (string) $id)->implode(',')
            ));

            if (! $fix || ! $canonical instanceof ContentPublication) {
                continue;
            }

            DB::transaction(function () use ($duplicates, $canonical, &$repaired): void {
                foreach ($duplicates as $duplicate) {
                    if (! $duplicate instanceof ContentPublication) {
                        continue;
                    }

                    $meta = is_array($duplicate->meta) ? $duplicate->meta : [];
                    $meta['integrity_repair'] = [
                        'demoted_at' => now()->toIso8601String(),
                        'canonical_publication_id' => (string) $canonical->id,
                        'reason' => 'duplicate_active_laravel_publication',
                    ];

                    $duplicate->forceFill([
                        'remote_status' => ContentPublication::REMOTE_DRAFT,
                        'meta' => $meta,
                    ])->save();

                    $repaired++;
                }
            });
        }

        if ($fix) {
            $this->info(sprintf('Demoted %d duplicate active publications.', $repaired));
        } else {
            $this->warn('Dry run only. Re-run with --fix to demote duplicate active publications.');
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<string,Collection<int,ContentPublication>>
     */
    private function duplicateActiveGroups(): Collection
    {
        return ContentPublication::query()
            ->where('provider', ContentPublication::PROVIDER_LARAVEL)
            ->where('delivery_status', ContentPublication::STATUS_DELIVERED)
            ->where(function ($query): void {
                $query->where('remote_status', ContentPublication::REMOTE_PUBLISHED)
                    ->orWhereNull('remote_status');
            })
            ->orderByDesc('last_delivered_at')
            ->orderByDesc('updated_at')
            ->get()
            ->groupBy(function (ContentPublication $publication): string {
                $locale = ContentPublication::normalizeLocale(
                    $publication->locale instanceof \App\Enums\SupportedLanguage
                        ? $publication->locale->value
                        : $publication->getRawOriginal('locale')
                ) ?? 'default';

                return implode('|', [
                    (string) $publication->content_id,
                    (string) ($publication->destination_id ?? ''),
                    (string) ($publication->client_site_id ?? ''),
                    $locale,
                ]);
            })
            ->filter(fn (Collection $group): bool => $group->count() > 1);
    }
}
