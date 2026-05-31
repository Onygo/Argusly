<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\IntegrationConnection;
use App\Models\User;
use App\Services\Integrations\Google\GoogleConnectionService;
use App\Services\Integrations\Google\GoogleOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class GoogleIntegrationController extends Controller
{
    public function connect(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        GoogleOAuthService $oauth,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user) ?? abort(403);
        $brand = $currentBrand->get($user);

        try {
            return redirect()->away($oauth->authorizationUrl($user, $account, $brand));
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('settings.integrations')
                ->with('google_error', $exception->getMessage());
        }
    }

    public function callback(Request $request, GoogleOAuthService $oauth): RedirectResponse
    {
        if ($request->filled('error')) {
            return redirect()
                ->route('settings.integrations')
                ->with('google_error', $this->consentErrorMessage($request));
        }

        $validated = $request->validate([
            'state' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        try {
            $oauth->connectFromCallback($request->user(), $validated['state'], $validated['code']);

            return redirect()
                ->route('settings.integrations')
                ->with('google_status', 'Google connected.');
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('settings.integrations')
                ->with('google_error', $exception->getMessage());
        }
    }

    public function disconnect(
        Request $request,
        IntegrationConnection $connection,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        GoogleConnectionService $connections,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user) ?? abort(403);
        $brand = $currentBrand->get($user);

        abort_unless($connection->account_id === $account->id, 404);
        abort_unless($connection->brand_id === null || $connection->brand_id === $brand?->id, 404);
        abort_unless($connection->integration?->key === 'google', 404);
        abort_unless($connection->owner_user_id === $user->id, 403);

        $connections->disconnect($connection);

        return redirect()
            ->back()
            ->with('google_status', 'Google disconnected.');
    }

    private function consentErrorMessage(Request $request): string
    {
        if ($request->string('error')->toString() === 'access_denied') {
            return 'Google consent was denied. No connection was created.';
        }

        return 'Google could not complete the connection. Please try again.';
    }
}
