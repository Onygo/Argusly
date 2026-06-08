<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

class AnalyticsScriptController extends Controller
{
    public function __invoke(): Response
    {
        $script = $this->getScript();
        $cacheSeconds = (int) config('analytics.script.cache_seconds', 3600);

        return response($script, 200)
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            ->header('Cache-Control', "public, max-age={$cacheSeconds}, immutable")
            ->header('X-Content-Type-Options', 'nosniff');
    }

    private function getScript(): string
    {
        $path = resource_path('analytics/argusly.js');

        if (file_exists($path)) {
            return file_get_contents($path);
        }

        // Fallback minimal script if file doesn't exist
        return '// Argusly Analytics - script not found';
    }
}
