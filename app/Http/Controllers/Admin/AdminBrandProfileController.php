<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DefaultBrandProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminBrandProfileController extends Controller
{
    public function index(): View
    {
        $profiles = DefaultBrandProfile::query()
            ->latest('updated_at')
            ->paginate(20);

        return view('admin.brand-profiles.index', [
            'profiles' => $profiles,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatePayload($request);

        DefaultBrandProfile::query()->create($data);

        return back()->with('status', 'Default brand profile created.');
    }

    public function update(Request $request, DefaultBrandProfile $brandProfile): RedirectResponse
    {
        $data = $this->validatePayload($request);

        $brandProfile->update($data);

        return back()->with('status', 'Default brand profile updated.');
    }

    public function destroy(DefaultBrandProfile $brandProfile): RedirectResponse
    {
        $brandProfile->delete();

        return back()->with('status', 'Default brand profile removed.');
    }

    /**
     * @return array<string,mixed>
     */
    private function validatePayload(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'tone' => ['nullable', 'string', 'max:255'],
            'style_rules' => ['nullable', 'string'],
        ]);

        return [
            'name' => (string) $data['name'],
            'tone' => isset($data['tone']) ? trim((string) $data['tone']) : null,
            'style_rules' => $this->decodeJson($data['style_rules'] ?? null),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeJson(mixed $value): ?array
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : ['raw' => $raw];
    }
}
