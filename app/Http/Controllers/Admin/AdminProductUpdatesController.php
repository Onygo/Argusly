<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductUpdate;
use App\Support\SafeMarkdownRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AdminProductUpdatesController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeAdmin($request);

        $updates = ProductUpdate::query()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(25);

        return view('admin.product-updates.index', [
            'updates' => $updates,
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorizeAdmin($request);

        return view('admin.product-updates.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $payload = $this->validatedPayload($request);

        ProductUpdate::query()->create($payload);

        return redirect()
            ->route('admin.product-updates.index')
            ->with('status', 'Product update created.');
    }

    public function edit(Request $request, ProductUpdate $productUpdate, SafeMarkdownRenderer $renderer): View
    {
        $this->authorizeAdmin($request);

        $productUpdate->setAttribute('body_html', $renderer->render((string) $productUpdate->body_markdown));

        return view('admin.product-updates.edit', [
            'productUpdate' => $productUpdate,
        ]);
    }

    public function update(Request $request, ProductUpdate $productUpdate): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $payload = $this->validatedPayload($request);
        $productUpdate->update($payload);

        return redirect()
            ->route('admin.product-updates.edit', $productUpdate)
            ->with('status', 'Product update updated.');
    }

    public function destroy(Request $request, ProductUpdate $productUpdate): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $productUpdate->delete();

        return redirect()
            ->route('admin.product-updates.index')
            ->with('status', 'Product update deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'summary' => ['required', 'string', 'max:280'],
            'body_markdown' => ['required', 'string'],
            'version' => ['nullable', 'string', 'max:30'],
            'tags_input' => ['nullable', 'string', 'max:500'],
            'published_at' => ['required', 'date'],
            'is_public' => ['nullable', 'boolean'],
        ]);

        $tags = ProductUpdate::normalizeTags($data['tags_input'] ?? '');

        return [
            'title' => trim((string) $data['title']),
            'summary' => trim((string) $data['summary']),
            'body_markdown' => trim((string) $data['body_markdown']),
            'version' => filled($data['version'] ?? null) ? trim((string) $data['version']) : null,
            'tags' => $tags === [] ? null : $tags,
            'is_public' => $request->boolean('is_public'),
            'published_at' => Carbon::parse((string) $data['published_at']),
        ];
    }

    private function authorizeAdmin(Request $request): void
    {
        Gate::forUser($request->user())->authorize('admin-area-manage-approvals');
    }
}
