@if ($errors->any())
    <div class="mb-5 rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-700">
        {{ $errors->first() }}
    </div>
@endif

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block sm:col-span-2">
        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Name</span>
        <input name="name" value="{{ old('name', $topic->name) }}" required class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
    </label>

    <label class="block">
        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Slug</span>
        <input name="slug" value="{{ old('slug', $topic->slug) }}" placeholder="Generated from name" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
    </label>

    <label class="block">
        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Status</span>
        <select name="status" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
            @foreach ($statuses as $status)
                <option value="{{ $status }}" @selected(old('status', $topic->status ?? 'active') === $status)>{{ str($status)->headline() }}</option>
            @endforeach
        </select>
    </label>

    <label class="block sm:col-span-2">
        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Description</span>
        <textarea name="description" rows="5" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">{{ old('description', $topic->description) }}</textarea>
    </label>

    @if (current_brand())
        <label class="flex items-start gap-3 rounded-md border border-line bg-panel p-4">
            <input type="hidden" name="brand_scoped" value="0">
            <input type="checkbox" name="brand_scoped" value="1" @checked(old('brand_scoped', $topic->exists ? $topic->brand_id !== null : true)) class="mt-1 rounded border-line text-blue">
            <span>
                <span class="block text-sm font-semibold text-ink">Scope to {{ current_brand()->name }}</span>
                <span class="mt-1 block text-xs leading-5 text-muted">Brand-scoped topics are available to this brand and still roll up to the account.</span>
            </span>
        </label>
    @endif

    <label class="block">
        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Priority</span>
        <input name="priority" type="number" min="0" value="{{ old('priority', $topic->brands->first()?->pivot?->priority ?? 0) }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
    </label>

    <label class="block">
        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Importance score</span>
        <input name="importance_score" type="number" min="0" max="100" step="0.01" value="{{ old('importance_score', $topic->brands->first()?->pivot?->importance_score) }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
    </label>
</div>
