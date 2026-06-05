<?php

namespace App\Http\Controllers;

use App\Http\Requests\Public\AcceptEarlyAccessInviteRequest;
use App\Services\EarlyAccessActivationService;
use App\Services\EarlyAccessInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PublicEarlyAccessInviteController extends Controller
{
    public function show(string $token, EarlyAccessInvitationService $invites): View
    {
        $invite = $invites->resolveInvite($token);

        return view('auth.accept-early-access-invite', [
            'token' => $token,
            'invite' => $invite,
            'signup' => $invite->signup,
        ]);
    }

    public function store(
        AcceptEarlyAccessInviteRequest $request,
        string $token,
        EarlyAccessInvitationService $invites,
        EarlyAccessActivationService $activation
    ): RedirectResponse {
        $invite = $invites->resolveInvite($token);
        $activation->activateFromInvite($invite, $request->validated(), $request);

        return redirect()
            ->route('login')
            ->with('status', 'Early access account activated. Please log in.');
    }
}
