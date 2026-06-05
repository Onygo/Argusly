<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAnalyticsSettingsRequest;
use App\Services\Analytics\AnalyticsSettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class AdminAnalyticsController extends Controller
{
    public function __construct(
        private readonly AnalyticsSettingsService $analyticsSettings
    ) {}

    public function index(): View
    {
        Gate::authorize('admin-area-superadmin');

        $settings = $this->analyticsSettings->getSettings();
        $providers = AnalyticsSettingsService::PROVIDERS;
        $isTrackingAllowed = $this->analyticsSettings->isTrackingAllowedInEnvironment();

        return view('admin.analytics.index', [
            'title' => 'Analytics Settings',
            'settings' => $settings,
            'providers' => $providers,
            'isTrackingAllowed' => $isTrackingAllowed,
            'currentEnvironment' => app()->environment(),
        ]);
    }

    public function update(UpdateAnalyticsSettingsRequest $request): RedirectResponse
    {
        $this->analyticsSettings->updateSettings(
            $request->validated(),
            $request->user()
        );

        return redirect()
            ->route('admin.analytics.index')
            ->with('status', 'Analytics settings updated successfully.');
    }
}
