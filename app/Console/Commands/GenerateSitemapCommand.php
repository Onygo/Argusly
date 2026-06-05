<?php

namespace App\Console\Commands;

use App\Services\Sitemap\SitemapGenerator;
use Illuminate\Console\Command;

class GenerateSitemapCommand extends Command
{
    protected $signature = 'sitemap:generate {--host= : Cache scope host key} {--locale= : Restrict sitemap generation to a single locale} {--flush : Clear cached sitemap payloads before generating}';

    protected $description = 'Generate and cache sitemap index and child sitemap XML.';

    public function handle(SitemapGenerator $generator): int
    {
        if (! config('sitemap.enabled', true)) {
            $this->warn('Sitemaps are disabled.');

            return self::SUCCESS;
        }

        $locale = trim((string) $this->option('locale')) ?: null;
        $scope = trim((string) ($this->option('host') ?: parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'default'));
        if ($locale !== null) {
            $scope .= ':' . $locale;
        }

        $payload = $generator->generate($scope, (bool) $this->option('flush'), $locale);

        $this->info(sprintf(
            'Generated sitemap cache for scope [%s] with %d child sitemap(s).',
            $scope,
            count($payload['manifest'])
        ));

        foreach ($payload['manifest'] as $item) {
            $this->line(sprintf('- %s (%s, %d URL%s)', $item['name'], $item['type'], $item['url_count'], $item['url_count'] === 1 ? '' : 's'));
        }

        return self::SUCCESS;
    }
}
