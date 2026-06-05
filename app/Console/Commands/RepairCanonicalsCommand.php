<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Services\Seo\CanonicalUrlService;
use Illuminate\Console\Command;

class RepairCanonicalsCommand extends Command
{
    protected $signature = 'seo:repair-canonicals {--fix : Apply repairs} {--content-id=} {--verbose-output : Show each mismatch}';

    protected $description = 'Repair stored canonicals so they match the final public route.';

    public function handle(CanonicalUrlService $canonicals): int
    {
        $fix = (bool) $this->option('fix');
        $verbose = (bool) $this->option('verbose-output');

        $contents = Content::query()
            ->where('type', 'article')
            ->when($this->option('content-id'), fn ($query, $id) => $query->where('id', (string) $id))
            ->with('currentVersion')
            ->get();

        $affected = 0;

        foreach ($contents as $content) {
            $expected = $canonicals->expectedCanonicalForContent($content);
            $stored = $canonicals->normalize((string) ($content->seo_canonical ?? ''));

            if ($expected === null || $canonicals->equivalent($expected, $stored)) {
                continue;
            }

            $affected++;

            if ($verbose) {
                $this->line(sprintf('%s [%s]: %s -> %s', $content->title, $content->localeCode(), $stored ?: 'none', $expected));
            }

            if (! $fix) {
                continue;
            }

            $publishedUrl = $canonicals->normalize((string) ($content->published_url ?? ''));

            $content->forceFill([
                'seo_canonical' => $expected,
                'published_url' => $publishedUrl === null || $stored === $publishedUrl ? $expected : $content->published_url,
            ])->save();
        }

        $this->info($fix
            ? sprintf('Repaired %d canonical row(s).', $affected)
            : sprintf('Found %d canonical mismatch(es). Re-run with --fix to apply.', $affected));

        return self::SUCCESS;
    }
}
