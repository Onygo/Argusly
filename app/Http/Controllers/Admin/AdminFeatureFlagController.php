<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
use App\Support\FeatureFlags;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminFeatureFlagController extends Controller
{
    public function __construct(private readonly FeatureFlags $features)
    {
    }

    public function index(): View
    {
        return view('admin.feature-flags.index', [
            'flags' => $this->features->effectiveFlags(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9_\-.]+$/i', 'unique:feature_flags,key'],
            'description' => ['nullable', 'string', 'max:255'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        FeatureFlag::query()->create([
            'key' => strtolower((string) $data['key']),
            'description' => isset($data['description']) ? trim((string) $data['description']) : null,
            'enabled' => (bool) ($data['enabled'] ?? false),
        ]);

        return back()->with('status', 'Feature flag created.');
    }

    public function update(Request $request, FeatureFlag $featureFlag): RedirectResponse
    {
        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:255'],
            'enabled' => ['required', 'boolean'],
        ]);

        $featureFlag->update([
            'description' => isset($data['description']) ? trim((string) $data['description']) : $featureFlag->description,
            'enabled' => (bool) $data['enabled'],
        ]);

        return back()->with('status', 'Feature flag updated.');
    }
}
