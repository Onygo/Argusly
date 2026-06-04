<x-app.layout title="Feature Flags" :show-workspace-header="false">
    @include('admin._nav')

    <div class="mb-6">
        <p class="text-sm font-semibold uppercase tracking-[0.12em] text-muted">Platform</p>
        <h1 class="mt-1 text-3xl font-bold text-ink">Feature Flags</h1>
    </div>

    <div class="grid gap-4 xl:grid-cols-[0.9fr_1.4fr]">
        <form method="POST" action="{{ route('admin.platform.feature-flags.store') }}" class="rounded-md border border-line bg-white p-4">
            @csrf
            <h2 class="text-lg font-bold text-ink">Create flag</h2>
            <div class="mt-4 space-y-3">
                <input name="key" placeholder="key, e.g. agentic_marketing_beta" class="w-full rounded-md border border-line px-3 py-2 text-sm" required>
                <input name="name" placeholder="Name" class="w-full rounded-md border border-line px-3 py-2 text-sm" required>
                <textarea name="description" placeholder="Description" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
                <select name="scope" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                    @foreach ($scopes as $scope)
                        <option value="{{ $scope }}">{{ str($scope)->headline() }}</option>
                    @endforeach
                </select>
                <textarea name="rules" placeholder='{"account_ids":[1]}' class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
                <label class="flex items-center gap-2 text-sm font-semibold text-ink">
                    <input type="checkbox" name="enabled" value="1" class="rounded border-line">
                    Enabled
                </label>
                <button class="rounded-md bg-ink px-4 py-2 text-sm font-semibold text-white">Create</button>
            </div>
        </form>

        <div class="space-y-3">
            @forelse ($flags as $flag)
                <form method="POST" action="{{ route('admin.platform.feature-flags.update', $flag) }}" class="rounded-md border border-line bg-white p-4">
                    @csrf
                    @method('PATCH')
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-bold text-ink">{{ $flag->key }}</p>
                            <p class="text-sm text-muted">{{ $flag->description ?: $flag->name }}</p>
                        </div>
                        @include('admin._status', ['value' => $flag->enabled ? 'active' : 'disabled'])
                    </div>
                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        <input name="key" value="{{ $flag->key }}" class="rounded-md border border-line px-3 py-2 text-sm" required>
                        <input name="name" value="{{ $flag->name }}" class="rounded-md border border-line px-3 py-2 text-sm" required>
                        <select name="scope" class="rounded-md border border-line px-3 py-2 text-sm">
                            @foreach ($scopes as $scope)
                                <option value="{{ $scope }}" @selected($flag->scope === $scope)>{{ str($scope)->headline() }}</option>
                            @endforeach
                        </select>
                        <label class="flex items-center gap-2 text-sm font-semibold text-ink">
                            <input type="checkbox" name="enabled" value="1" class="rounded border-line" @checked($flag->enabled)>
                            Enabled
                        </label>
                        <textarea name="description" class="rounded-md border border-line px-3 py-2 text-sm md:col-span-2">{{ $flag->description }}</textarea>
                        <textarea name="rules" class="rounded-md border border-line px-3 py-2 text-sm md:col-span-2">{{ $flag->rules ? json_encode($flag->rules, JSON_PRETTY_PRINT) : '' }}</textarea>
                    </div>
                    <button class="mt-3 rounded-md border border-line px-4 py-2 text-sm font-semibold text-ink hover:bg-panel">Save</button>
                </form>
            @empty
                <p class="rounded-md border border-line bg-white p-4 text-sm text-muted">No feature flags yet.</p>
            @endforelse

            {{ $flags->links() }}
        </div>
    </div>
</x-app.layout>
