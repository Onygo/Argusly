<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

class AnalyticsScriptController extends Controller
{
    public function __invoke(): Response
    {
        $script = (string) file_get_contents(resource_path('analytics/argusly.js'));
        $cacheSeconds = (int) config('analytics.script.cache_seconds', 3600);

        return response($script, 200)
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            ->header('Cache-Control', "public, max-age={$cacheSeconds}, immutable")
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
