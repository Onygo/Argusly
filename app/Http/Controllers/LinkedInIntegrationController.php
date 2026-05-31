<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\IntegrationConnection;
use App\Models\User;
use App\Services\Integrations\LinkedIn\LinkedInConnectionService;
use App\Services\Integrations\LinkedIn\LinkedInOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class LinkedInIntegrationController extends Controller
{
    public function connect(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        LinkedInOAuthService $oauth,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user) ?? abort(403);
        $brand = $currentBrand->get($user);

        try {
            return redirect()->away($oauth->authorizationUrl($user, $account, $brand));
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('settings.integrations.linkedin')
                ->with('linkedin_error', $exception->getMessage());
        }
    }

    public function callback(Request $request, LinkedInOAuthService $oauth): RedirectResponse
    {
        if ($request->filled('error')) {
            return redirect()
                ->route('settings.integrations.linkedin')
                ->with('linkedin_error', $this->consentErrorMessage($request));
        }

        $validated = $request->validate([
            'state' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        try {
            $oauth->connectFromCallback($request->user(), $validated['state'], $validated['code']);

            return redirect()
                ->route('settings.integrations.linkedin')
                ->with('linkedin_status', 'LinkedIn profile connected.');
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('settings.integrations.linkedin')
                ->with('linkedin_error', $exception->getMessage());
        }
    }

    public function disconnect(
        Request $request,
        IntegrationConnection $connection,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        LinkedInConnectionService $connections,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user) ?? abort(403);
        $brand = $currentBrand->get($user);

        abort_unless($connection->account_id === $account->id, 404);
        abort_unless($connection->brand_id === null || $connection->brand_id === $brand?->id, 404);
        abort_unless($connection->integration?->key === 'linkedin', 404);
        abort_unless($connection->owner_user_id === $user->id, 403);

        $connections->disconnect($connection);

        return redirect()
            ->route('settings.integrations.linkedin')
            ->with('linkedin_status', 'LinkedIn profile disconnected.');
    }

    private function consentErrorMessage(Request $request): string
    {
        if ($request->string('error')->toString() === 'access_denied') {
            return 'LinkedIn consent was denied. No connection was created.';
        }

        return 'LinkedIn could not complete the connection. Please try again.';
    }
}
