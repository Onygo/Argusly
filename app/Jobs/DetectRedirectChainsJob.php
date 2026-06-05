<?php

namespace App\Jobs;

use App\Models\MarketingBlogRedirect;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DetectRedirectChainsJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $redirects = MarketingBlogRedirect::query()->active()->get()->keyBy('source_path');

        foreach ($redirects as $redirect) {
            $visited = [];
            $targetPath = (string) $redirect->target_path;

            while ($targetPath !== '' && isset($redirects[$targetPath]) && ! in_array($targetPath, $visited, true)) {
                $visited[] = $targetPath;
                $targetPath = (string) $redirects[$targetPath]->target_path;
            }

            if ($targetPath !== '' && $targetPath !== (string) $redirect->target_path) {
                $meta = is_array($redirect->meta) ? $redirect->meta : [];
                $meta['detected_redirect_chain_at'] = now()->toIso8601String();
                $meta['final_target_path'] = $targetPath;
                $redirect->forceFill(['meta' => $meta])->save();
            }
        }
    }
}
