<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\TaxonomyItem;
use App\Models\TaxonomySet;
use App\Support\EditorialTaxonomyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminEditorialTaxonomyController extends Controller
{
    public function index(Request $request, EditorialTaxonomyService $taxonomyService): View
    {
        $taxonomyService->ensureDefaults(0);

        $setFilter = trim((string) $request->query('set', ''));
        $typeFilter = strtolower(trim((string) $request->query('type', '')));
        if (! in_array($typeFilter, TaxonomyItem::allowedTypes(), true)) {
            $typeFilter = '';
        }

        $sets = TaxonomySet::query()
            ->withCount('items')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $selectedSet = $sets->firstWhere('id', (int) $setFilter) ?: $sets->first();

        $organizations = Organization::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        $assignedTenantIds = collect();
        $items = collect();
        $parentOptions = collect();

        if ($selectedSet) {
            $assignedTenantIds = $selectedSet->tenants()->pluck('tenant_id');

            $itemsQuery = TaxonomyItem::query()
                ->where('taxonomy_set_id', $selectedSet->id)
                ->with('parent')
                ->orderBy('type')
                ->orderBy('name');

            if ($typeFilter !== '') {
                $itemsQuery->where('type', $typeFilter);
            }

            $items = $itemsQuery->get();
            $parentOptions = TaxonomyItem::query()
                ->where('taxonomy_set_id', $selectedSet->id)
                ->orderBy('type')
                ->orderBy('name')
                ->get(['id', 'type', 'name']);
        }

        return view('admin.editorial-taxonomy.index', [
            'sets' => $sets,
            'selectedSet' => $selectedSet,
            'items' => $items,
            'parentOptions' => $parentOptions,
            'organizations' => $organizations,
            'assignedTenantIds' => $assignedTenantIds,
            'typeFilter' => $typeFilter,
            'allowedTypes' => TaxonomyItem::allowedTypes(),
        ]);
    }

    public function storeSet(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:140'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $set = TaxonomySet::query()->create([
            'name' => trim((string) $data['name']),
            'description' => $this->nullableTrim($data['description'] ?? null),
            'is_default' => (bool) ($data['is_default'] ?? false),
        ]);

        return redirect()
            ->route('admin.editorial-taxonomy.index', ['set' => $set->id])
            ->with('status', 'Taxonomy set created.');
    }

    public function updateSet(Request $request, TaxonomySet $set): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:140'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $set->update([
            'name' => trim((string) $data['name']),
            'description' => $this->nullableTrim($data['description'] ?? null),
            'is_default' => (bool) ($data['is_default'] ?? false),
        ]);

        return redirect()
            ->route('admin.editorial-taxonomy.index', ['set' => $set->id])
            ->with('status', 'Taxonomy set updated.');
    }

    public function destroySet(TaxonomySet $set): RedirectResponse
    {
        $set->delete();

        return redirect()
            ->route('admin.editorial-taxonomy.index')
            ->with('status', 'Taxonomy set deleted.');
    }

    public function updateAssignments(Request $request, TaxonomySet $set): RedirectResponse
    {
        $data = $request->validate([
            'tenant_ids' => ['nullable', 'array'],
            'tenant_ids.*' => ['integer', Rule::exists('organizations', 'id')],
        ]);

        $tenantIds = collect((array) ($data['tenant_ids'] ?? []))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $set->tenants()->sync($tenantIds);

        return redirect()
            ->route('admin.editorial-taxonomy.index', ['set' => $set->id])
            ->with('status', 'Tenant assignments updated.');
    }

    public function storeItem(Request $request, TaxonomySet $set, EditorialTaxonomyService $taxonomyService): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(TaxonomyItem::allowedTypes())],
            'name' => ['required', 'string', 'max:140'],
            'slug' => ['nullable', 'string', 'max:140'],
            'parent_id' => ['nullable', Rule::exists('taxonomy_items', 'id')->where('taxonomy_set_id', $set->id)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $slugInput = trim((string) ($data['slug'] ?? ''));
        $slug = $taxonomyService->normalizeKey($slugInput !== '' ? $slugInput : (string) $data['name']);
        $this->assertUniqueSlugInSet($set->id, $slug);

        TaxonomyItem::query()->create([
            'taxonomy_set_id' => $set->id,
            'type' => (string) $data['type'],
            'name' => trim((string) $data['name']),
            'slug' => $slug,
            'parent_id' => $data['parent_id'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        return redirect()
            ->route('admin.editorial-taxonomy.index', ['set' => $set->id])
            ->with('status', 'Taxonomy item created.');
    }

    public function updateItem(
        Request $request,
        TaxonomySet $set,
        TaxonomyItem $item,
        EditorialTaxonomyService $taxonomyService
    ): RedirectResponse {
        $this->assertItemInSet($set, $item);

        $data = $request->validate([
            'type' => ['required', Rule::in(TaxonomyItem::allowedTypes())],
            'name' => ['required', 'string', 'max:140'],
            'slug' => ['nullable', 'string', 'max:140'],
            'parent_id' => [
                'nullable',
                Rule::exists('taxonomy_items', 'id')
                    ->where('taxonomy_set_id', $set->id),
                Rule::notIn([$item->id]),
            ],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $slugInput = trim((string) ($data['slug'] ?? ''));
        $slug = $taxonomyService->normalizeKey($slugInput !== '' ? $slugInput : (string) $data['name']);
        $this->assertUniqueSlugInSet($set->id, $slug, (int) $item->id);

        $item->update([
            'type' => (string) $data['type'],
            'name' => trim((string) $data['name']),
            'slug' => $slug,
            'parent_id' => $data['parent_id'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        return redirect()
            ->route('admin.editorial-taxonomy.index', ['set' => $set->id])
            ->with('status', 'Taxonomy item updated.');
    }

    public function destroyItem(TaxonomySet $set, TaxonomyItem $item): RedirectResponse
    {
        $this->assertItemInSet($set, $item);
        $item->delete();

        return redirect()
            ->route('admin.editorial-taxonomy.index', ['set' => $set->id])
            ->with('status', 'Taxonomy item deleted.');
    }

    private function assertItemInSet(TaxonomySet $set, TaxonomyItem $item): void
    {
        if ((int) $item->taxonomy_set_id !== (int) $set->id) {
            abort(404);
        }
    }

    private function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function assertUniqueSlugInSet(int $setId, string $slug, ?int $ignoreId = null): void
    {
        $exists = TaxonomyItem::query()
            ->where('taxonomy_set_id', $setId)
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'slug' => ['Slug must be unique within the selected taxonomy set.'],
            ]);
        }
    }
}
