<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\EmailProvider;
use App\Models\User;
use App\Services\EmailProviderManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EmailProviderController extends Controller
{
    public function __construct(
        private readonly CurrentAccountContract $currentAccount,
        private readonly CurrentBrandContract $currentBrand,
        private readonly EmailProviderManager $providers,
    ) {}

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user);
        $brand = $this->currentBrand->get($user);

        abort_unless($account, 403);

        return view('app.settings.email-providers', [
            'account' => $account,
            'brand' => $brand,
            'providers' => $this->providers->paginatedForTenant($account, $brand),
            'providerTypes' => EmailProvider::PROVIDERS,
            'statuses' => EmailProvider::STATUSES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user);
        $brand = $this->currentBrand->get($user);

        abort_unless($account, 403);

        $attributes = $request->validate([
            'scope' => ['required', 'string', Rule::in(['brand', 'account'])],
            'provider' => ['required', 'string', Rule::in(EmailProvider::PROVIDERS)],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', Rule::in(EmailProvider::STATUSES)],
            'from_email' => ['nullable', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'credential_label' => ['nullable', 'string', 'max:255'],
            'secret' => ['nullable', 'string', 'max:2048'],
        ]);

        $attributes['settings'] = array_filter([
            'from_email' => $attributes['from_email'] ?? null,
            'from_name' => $attributes['from_name'] ?? null,
            'placeholder' => true,
        ], fn ($value) => $value !== null);
        $attributes['credentials'] = array_filter([
            'label' => $attributes['credential_label'] ?? null,
            'secret' => $attributes['secret'] ?? null,
        ], fn ($value) => $value !== null);

        $this->providers->create($account, $brand, $attributes);

        return redirect()->route('settings.email-providers')->with('status', 'Email provider created.');
    }

    public function sendTest(Request $request, EmailProvider $emailProvider): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user);
        $brand = $this->currentBrand->get($user);

        abort_unless($account, 403);
        $provider = $this->providers->findForTenant($account, $brand, $emailProvider->id);

        $attributes = $request->validate([
            'to' => ['required', 'email', 'max:255'],
        ]);

        $result = $this->providers->sendTestEmail($provider, $attributes['to']);

        return redirect()
            ->route('settings.email-providers')
            ->with('status', "Fake test email queued to {$result['to']} ({$result['message_id']}).");
    }
}
