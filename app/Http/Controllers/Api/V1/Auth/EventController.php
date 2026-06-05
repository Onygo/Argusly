<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function store(Request $request)
    {
        $siteToken = $request->attributes->get('siteToken');
        if (!$siteToken->hasScope('events:write')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $clientSite = $request->attributes->get('clientSite');

        $data = $request->validate([
            'type' => ['required','string'],
            'occurred_at' => ['required','date'],
            'data' => ['nullable','array'],
            'client' => ['nullable','array'],
        ]);

        $event = Event::create([
            'client_site_id' => $clientSite->id,
            'type' => $data['type'],
            'occurred_at' => $data['occurred_at'],
            'data' => $data['data'] ?? null,
        ]);

        return response()->json(['ok' => true, 'id' => $event->id], 201);
    }
}
