<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Brief;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class BriefTestDraftController extends Controller
{
    public function send(Request $request, string $id)
    {
        $siteToken = $request->attributes->get('siteToken');
        if (!$siteToken || !$siteToken->hasScope('briefs:write')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $clientSite = $request->attributes->get('clientSite');
        if (!$clientSite) {
            return response()->json(['error' => 'Client site not resolved'], 401);
        }

        $brief = Brief::where('client_site_id', $clientSite->id)
            ->where('id', $id)
            ->firstOrFail();

        $url = $clientSite->draft_webhook_url
            ?: ($brief->client_refs['draft_webhook_url'] ?? null);

        $secret = $clientSite->draft_webhook_secret
            ?: ($brief->client_refs['draft_webhook_secret'] ?? null);

        if (!$url || !$secret) {
            return response()->json(['error' => 'Missing webhook url or secret'], 422);
        }

        // --------------------
        // Build deterministic payload
        // --------------------
        $payload = [
            'id' => 'test_' . now()->format('YmdHis'),
            'brief_id' => $brief->id,
            'title' => 'Test draft for: ' . $brief->title,
            'content_html' => '<p>Dit is een test draft vanuit Argusly Laravel naar WordPress.</p>',
            'output_type' => $brief->output_type,
            'meta' => [
                'source' => 'laravel-test',
            ],
        ];

        // IMPORTANT: deterministic JSON for HMAC
        $raw = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $ts = (string) time();
        $sig = hash_hmac('sha256', $ts . '.' . $raw, $secret);

        // --------------------
        // Send EXACT raw body
        // --------------------
        $resp = Http::withoutVerifying()
            ->timeout(15)
            ->withHeaders([
                'X-PublishLayer-Timestamp' => $ts,
                'X-PublishLayer-Signature' => $sig,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->send('POST', $url, [
                'body' => $raw,
            ]);

        return response()->json([
            'webhook_url' => $url,
            'status' => $resp->status(),
            'body' => $resp->json(),
        ], $resp->successful() ? 200 : 502);
    }
}
