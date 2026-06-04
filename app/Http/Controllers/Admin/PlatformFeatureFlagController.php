<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use JsonException;

class PlatformFeatureFlagController extends Controller
{
    public function index(): View
    {
        return view('admin.platform.feature-flags', [
            'flags' => FeatureFlag::query()->with(['creator', 'updater'])->orderBy('key')->paginate(30),
            'scopes' => FeatureFlag::SCOPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['enabled'] = $request->boolean('enabled');
        $data['rules'] = $this->decodeRules($data['rules'] ?? null);
        $data['created_by'] = $request->user()?->id;
        $data['updated_by'] = $request->user()?->id;

        FeatureFlag::query()->create($data);

        return back()->with('status', 'Feature flag created.');
    }

    public function update(Request $request, FeatureFlag $featureFlag): RedirectResponse
    {
        $data = $this->validated($request, $featureFlag);
        $data['enabled'] = $request->boolean('enabled');
        $data['rules'] = $this->decodeRules($data['rules'] ?? null);
        $data['updated_by'] = $request->user()?->id;

        $featureFlag->update($data);

        return back()->with('status', 'Feature flag updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?FeatureFlag $featureFlag = null): array
    {
        return $request->validate([
            'key' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9_.-]+$/', Rule::unique('feature_flags', 'key')->ignore($featureFlag)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'scope' => ['required', Rule::in(FeatureFlag::SCOPES)],
            'enabled' => ['nullable', 'boolean'],
            'rules' => ['nullable', 'string'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeRules(?string $rules): ?array
    {
        if ($rules === null || trim($rules) === '') {
            return null;
        }

        try {
            return json_decode($rules, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'rules' => 'Rules must be valid JSON.',
            ]);
        }
    }
}
