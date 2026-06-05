<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Services\Content\LocaleIntegrityValidationService;
use Illuminate\Console\Command;

class RepairHreflangCommand extends Command
{
    protected $signature = 'seo:repair-hreflang {--content-id=} {--verbose-output : Show issue details}';

    protected $description = 'Audit hreflang and locale family integrity for public content.';

    public function handle(LocaleIntegrityValidationService $integrity): int
    {
        $verbose = (bool) $this->option('verbose-output');

        $contents = Content::query()
            ->where('type', 'article')
            ->when($this->option('content-id'), fn ($query, $id) => $query->where('id', (string) $id))
            ->with(['localizedVariants.currentVersion', 'localizedVariants.publications', 'publications', 'currentVersion'])
            ->get();

        $issues = 0;

        foreach ($contents as $content) {
            foreach ($integrity->validate($content)['issues'] as $issue) {
                $issues++;

                if ($verbose) {
                    $this->line(sprintf(
                        '%s [%s] %s: %s',
                        $content->title,
                        $content->localeCode(),
                        strtoupper((string) $issue['severity']),
                        (string) $issue['message']
                    ));
                }
            }
        }

        $this->info(sprintf('Hreflang audit completed with %d issue(s).', $issues));

        return self::SUCCESS;
    }
}
