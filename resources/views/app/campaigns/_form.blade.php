@if ($errors->any())
    <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
        {{ $errors->first() }}
    </div>
@endif

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block sm:col-span-2">
        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Name</span>
        <input name="name" value="{{ old('name', $campaign->name) }}" required class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
    </label>

    <label class="block">
        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Slug</span>
        <input name="slug" value="{{ old('slug', $campaign->slug) }}" placeholder="Generated from name" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
    </label>

    <label class="block">
        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Type</span>
        <select name="campaign_type" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
            @foreach ($types as $type)
                <option value="{{ $type }}" @selected(old('campaign_type', $campaign->metadata['campaign_type'] ?? 'content') === $type)>{{ str($type)->headline() }}</option>
            @endforeach
        </select>
    </label>

    <label class="block">
        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Status</span>
        <select name="status" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
            @foreach ($statuses as $status)
                <option value="{{ $status }}" @selected(old('status', $campaign->status ?? 'draft') === $status)>{{ str($status)->headline() }}</option>
            @endforeach
        </select>
    </label>

    <label class="block">
        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Start date</span>
        <input name="start_date" type="date" value="{{ old('start_date', $campaign->start_date?->format('Y-m-d')) }}" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
    </label>

    <label class="block">
        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">End date</span>
        <input name="end_date" type="date" value="{{ old('end_date', $campaign->end_date?->format('Y-m-d')) }}" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
    </label>

    <label class="block sm:col-span-2">
        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Objective</span>
        <textarea name="objective" rows="3" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">{{ old('objective', $campaign->objective) }}</textarea>
    </label>

    <label class="block sm:col-span-2">
        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Description</span>
        <textarea name="description" rows="4" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">{{ old('description', $campaign->description) }}</textarea>
    </label>

    <label class="block">
        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Content assets</span>
        <select name="content_asset_ids[]" multiple size="7" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
            @foreach ($assets as $asset)
                <option value="{{ $asset->id }}" @selected($campaign->contentAssets->contains('id', $asset->id))>{{ $asset->title }}</option>
            @endforeach
        </select>
    </label>

    <label class="block">
        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Topics</span>
        <select name="topic_ids[]" multiple size="7" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
            @foreach ($topics as $topic)
                <option value="{{ $topic->id }}" @selected($campaign->topics->contains('id', $topic->id))>{{ $topic->name }}</option>
            @endforeach
        </select>
    </label>

    <label class="block sm:col-span-2">
        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Intelligence signals</span>
        <select name="signal_ids[]" multiple size="7" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
            @foreach ($signals as $signal)
                <option value="{{ $signal->id }}" @selected($campaign->signals->contains('id', $signal->id))>{{ $signal->title }}</option>
            @endforeach
        </select>
    </label>
</div>
