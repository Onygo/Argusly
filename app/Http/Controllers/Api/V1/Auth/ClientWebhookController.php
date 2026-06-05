<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\ClientSite;
use App\Models\WebhookEndpoint;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ClientWebhookController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'client_site_id' => ['required','uuid'],
            'event_type' => ['nullable','string'],
            'url' => ['required','string'],
            'signing.method' => ['nullable','string'],
            'signing.secret' => ['nullable','string'],
        ]);

        $site = ClientSite::findOrFail($data['client_site_id']);

        $secret = $data['signing']['secret'] ?? Str::random(48);

        $wh = WebhookEndpoint::create([
            'client_site_id' => $site->id,
            'event_type' => $data['event_type'] ?? 'draft.ready',
            'url' => $data['url'],
            'signing_method' => $data['signing']['method'] ?? 'hmac_sha256',
            'secret' => $secret,
            'is_active' => true,
        ]);

        return response()->json([
            'ok' => true,
            'id' => $wh->id,
            'secret' => $secret,
        ], 201);
    }
}
