<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsConfigController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $publicKey = trim((string) $request->query('site', ''));

        if ($publicKey === '') {
            return response()->json(['error' => 'Missing site parameter'], 400);
        }

        $site = AnalyticsSite::query()->where('public_key', $publicKey)->first();

        if (! $site) {
            return response()->json(['error' => 'Site not found'], 404);
        }

        if (! $site->is_enabled) {
            return response()->json(['allowed' => false, 'reason' => 'disabled']);
        }

        if (! $site->verified_at) {
            return response()->json(['allowed' => false, 'reason' => 'unverified']);
        }

        return response()->json([
            'allowed' => true,
            'respectDnt' => $site->respect_dnt,
            'sampling' => $site->sampling_rate,
        ]);
    }
}
