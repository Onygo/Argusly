<?php

namespace App\Console\Commands;

use App\Models\MarketingBlogRedirect;
use Illuminate\Console\Command;

class RepairRedirectChainsCommand extends Command
{
    protected $signature = 'seo:repair-redirect-chains {--fix : Flatten active chains}';

    protected $description = 'Flatten redirect chains so old slugs point directly to the final canonical route.';

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');
        $redirects = MarketingBlogRedirect::query()->active()->get()->keyBy('source_path');
        $repairs = 0;

        foreach ($redirects as $redirect) {
            $visited = [];
            $targetPath = (string) $redirect->target_path;

            while ($targetPath !== '' && isset($redirects[$targetPath]) && ! in_array($targetPath, $visited, true)) {
                $visited[] = $targetPath;
                $targetPath = (string) $redirects[$targetPath]->target_path;
            }

            if ($targetPath === '' || $targetPath === (string) $redirect->target_path) {
                continue;
            }

            $repairs++;

            if (! $fix) {
                $this->line(sprintf('%s -> %s', $redirect->source_path, $targetPath));
                continue;
            }

            $meta = is_array($redirect->meta) ? $redirect->meta : [];
            $meta['chain_repaired_at'] = now()->toIso8601String();

            $redirect->forceFill([
                'target_path' => $targetPath,
                'meta' => $meta,
            ])->save();
        }

        $this->info($fix
            ? sprintf('Repaired %d redirect chain(s).', $repairs)
            : sprintf('Found %d redirect chain(s). Re-run with --fix to apply.', $repairs));

        return self::SUCCESS;
    }
}
