<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Credits\CreditQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditsController extends Controller
{
    public function show(Request $request, CreditQuoteService $quotes): JsonResponse
    {
        $siteToken = $request->attributes->get('siteToken');
        if (! $siteToken) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $clientSite = $request->attributes->get('clientSite');
        if (! $clientSite) {
            return response()->json(['error' => 'Client site not resolved'], 401);
        }

        return response()->json($quotes->walletSnapshot((string) $clientSite->id));
    }

    public function quote(Request $request, CreditQuoteService $quotes): JsonResponse
    {
        $siteToken = $request->attributes->get('siteToken');
        if (! $siteToken) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $clientSite = $request->attributes->get('clientSite');
        if (! $clientSite) {
            return response()->json(['error' => 'Client site not resolved'], 401);
        }

        $action = strtolower(trim((string) $request->query('action', $request->input('action', ''))));
        if (! in_array($action, ['draft_generate', 'image_generate'], true)) {
            return response()->json([
                'error' => 'Invalid action. Use draft_generate or image_generate.',
            ], 422);
        }

        $payloadRaw = $request->query('payload', $request->input('payload', []));
        if (is_string($payloadRaw)) {
            $decoded = json_decode($payloadRaw, true);
            $payload = is_array($decoded) ? $decoded : [];
        } elseif (is_array($payloadRaw)) {
            $payload = $payloadRaw;
        } else {
            $payload = [];
        }

        $required = $quotes->requiredCreditsForAction($action, $payload);

        return response()->json([
            'action' => $action,
            'required_credits' => $required,
            'site_id' => (string) $clientSite->id,
            'workspace_id' => (string) ($clientSite->workspace_id ?? ''),
            'quoted_at' => now()->toIso8601String(),
        ]);
    }
}
