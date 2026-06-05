<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Services\Content\LocaleIntegrityValidationService;
use Illuminate\Console\Command;

class DetectDuplicateLocalesCommand extends Command
{
    protected $signature = 'seo:detect-duplicate-locales {--content-id=}';

    protected $description = 'Detect duplicate locale rows inside content families.';

    public function handle(LocaleIntegrityValidationService $integrity): int
    {
        $contents = Content::query()
            ->where('type', 'article')
            ->when($this->option('content-id'), fn ($query, $id) => $query->where('id', (string) $id))
            ->with(['localizedVariants.currentVersion', 'localizedVariants.publications', 'publications', 'currentVersion'])
            ->get();

        $duplicates = 0;

        foreach ($contents as $content) {
            foreach ($integrity->validate($content)['issues'] as $issue) {
                if ((string) $issue['code'] !== 'duplicate_locale_content') {
                    continue;
                }

                $duplicates++;
                $this->line(sprintf('%s [%s] %s', $content->title, $content->localeCode(), (string) $issue['message']));
            }
        }

        $this->info(sprintf('Found %d duplicate locale issue(s).', $duplicates));

        return self::SUCCESS;
    }
}
