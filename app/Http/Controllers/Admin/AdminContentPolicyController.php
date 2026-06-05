<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentPolicyDefinition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminContentPolicyController extends Controller
{
    public function index(): View
    {
        $policies = ContentPolicyDefinition::query()
            ->latest('updated_at')
            ->paginate(20);

        return view('admin.content-policies.index', [
            'policies' => $policies,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatePayload($request);

        ContentPolicyDefinition::query()->create($data);

        return back()->with('status', 'Content policy created.');
    }

    public function update(Request $request, ContentPolicyDefinition $contentPolicy): RedirectResponse
    {
        $data = $this->validatePayload($request);

        $contentPolicy->update($data);

        return back()->with('status', 'Content policy updated.');
    }

    public function destroy(ContentPolicyDefinition $contentPolicy): RedirectResponse
    {
        $contentPolicy->delete();

        return back()->with('status', 'Content policy removed.');
    }

    /**
     * @return array<string,mixed>
     */
    private function validatePayload(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'rules' => ['nullable', 'string'],
        ]);

        return [
            'name' => (string) $data['name'],
            'description' => isset($data['description']) ? trim((string) $data['description']) : null,
            'rules' => $this->decodeJson($data['rules'] ?? null),
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
