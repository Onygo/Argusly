<?php

namespace App\Http\Controllers\Auth;

use App\Events\Onboarding\UserFirstLogin as UserFirstLoginEvent;
use App\Http\Controllers\Controller;
use App\Support\DomainHelper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            $user = $request->user();

            // Admin users go to admin subdomain
            if ($user && $user->is_admin) {
                return redirect()->to(DomainHelper::url('admin', '/dashboard'));
            }

            if ($user && $user->needsEmailCodeVerification()) {
                return redirect()->route('verify-code.show');
            }

            if (! $user || ! $user->isApproved()) {
                return redirect()->route('pending');
            }

            if ($user->organization && $user->organization->status === 'on_hold') {
                return redirect()->route('on-hold');
            }

            if (! $user->is_admin) {
                UserFirstLoginEvent::dispatch($user->id);
            }

            // Regular users stay on app subdomain
            return redirect()->route('app.dashboard');
        }

        return back()->withErrors([
            'email' => 'De combinatie van e-mail en wachtwoord is ongeldig.',
        ])->onlyInput('email');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Logout redirects to marketing subdomain
        return redirect()->to(DomainHelper::url('marketing', '/'));
    }
}
