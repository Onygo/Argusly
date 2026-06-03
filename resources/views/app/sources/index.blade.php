<x-app.layout title="Sources | Argusly">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Source registry</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Sources</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Register monitored streams and corpora for social, news, blog, forum, video, podcast, website, AI and search data.</p>
            </div>
            <x-ui.button href="{{ route('app.sources.syncs') }}" variant="secondary">Sync history</x-ui.button>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-md border border-line bg-white p-4 text-sm font-medium text-ink">{{ session('status') }}</div>
        @endif

        <div class="mt-8 grid gap-6 xl:grid-cols-[0.8fr_1.2fr]">
            <x-dashboard.section title="Create source" description="Configure a data stream or corpus. Sync history tracks ingestion runs separately.">
                <form method="POST" action="{{ route('app.sources.store') }}" class="space-y-4">
                    @csrf
                    @if ($errors->any())
                        <div class="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-700">{{ $errors->first() }}</div>
                    @endif
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Name</span>
                        <input name="name" value="{{ old('name') }}" required class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    </label>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Type</span>
                            <select name="type" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                @foreach ($types as $type)
                                    <option value="{{ $type }}" @selected(old('type') === $type)>{{ str($type)->headline() }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Provider</span>
                            <select name="provider" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                @foreach ($providers as $provider)
                                    <option value="{{ $provider }}" @selected(old('provider') === $provider)>{{ str($provider)->headline() }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Status</span>
                            <select name="status" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                @foreach ($statuses as $status)
                                    <option value="{{ $status }}" @selected(old('status', 'active') === $status)>{{ str($status)->headline() }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Scope</span>
                            <select name="scope" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                <option value="brand" @selected(old('scope', 'brand') === 'brand')>Current brand</option>
                                <option value="account" @selected(old('scope') === 'account')>Account</option>
                                <option value="global" @selected(old('scope') === 'global')>Global</option>
                            </select>
                        </label>
                    </div>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Credential</span>
                        <select name="integration_connection_id" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                            <option value="">No credential</option>
                            @foreach ($connections as $connection)
                                <option value="{{ $connection->id }}" @selected((string) old('integration_connection_id') === (string) $connection->id)>{{ $connection->name }} · {{ $connection->integration?->name ?? 'Integration' }}</option>
                            @endforeach
                        </select>
                    </label>
                    <x-ui.button type="submit">Create source</x-ui.button>
                </form>
            </x-dashboard.section>

            <x-dashboard.section title="Source Registry" description="Filter configured source lanes by provider, type, status and scope.">
                <form method="GET" action="{{ route('app.sources.index') }}" class="mb-5 grid gap-3 md:grid-cols-4">
                    <select name="type" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">All types</option>
                        @foreach ($types as $type)
                            <option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ str($type)->headline() }}</option>
                        @endforeach
                    </select>
                    <select name="provider" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">All providers</option>
                        @foreach ($providers as $provider)
                            <option value="{{ $provider }}" @selected(($filters['provider'] ?? '') === $provider)>{{ str($provider)->headline() }}</option>
                        @endforeach
                    </select>
                    <select name="status" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">All statuses</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ str($status)->headline() }}</option>
                        @endforeach
                    </select>
                    <select name="scope" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">All scopes</option>
                        <option value="brand" @selected(($filters['scope'] ?? '') === 'brand')>Brand</option>
                        <option value="account" @selected(($filters['scope'] ?? '') === 'account')>Account</option>
                        <option value="global" @selected(($filters['scope'] ?? '') === 'global')>Global</option>
                    </select>
                    <div class="md:col-span-4">
                        <x-ui.button type="submit" variant="secondary">Filter</x-ui.button>
                    </div>
                </form>

                @if ($sources->isEmpty())
                    <x-dashboard.empty-state title="No sources" message="Create a monitored stream or corpus before attaching ingestion runs." />
                @else
                    <div class="space-y-3">
                        @foreach ($sources as $source)
                            <a href="{{ route('app.sources.show', $source) }}" class="block rounded-md border border-line bg-panel p-4 transition hover:border-slate-300 hover:bg-white">
                                <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="truncate text-sm font-semibold text-ink">{{ $source->name }}</p>
                                            <x-ui.badge variant="{{ $source->status === 'active' ? 'success' : 'default' }}">{{ str($source->status)->headline() }}</x-ui.badge>
                                            <x-ui.badge>{{ str($source->provider)->headline() }}</x-ui.badge>
                                            <x-ui.badge>{{ str($source->type)->headline() }}</x-ui.badge>
                                        </div>
                                        <p class="mt-2 text-xs text-muted">{{ $source->brand?->name ?? ($source->account_id ? 'Account scope' : 'Global scope') }}</p>
                                    </div>
                                    <div class="grid shrink-0 grid-cols-2 gap-2 text-center">
                                        <div class="rounded-md border border-line bg-white px-3 py-2">
                                            <p class="text-sm font-semibold text-ink">{{ $source->connections_count }}</p>
                                            <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Connections</p>
                                        </div>
                                        <div class="rounded-md border border-line bg-white px-3 py-2">
                                            <p class="text-sm font-semibold text-ink">{{ $source->syncs_count }}</p>
                                            <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Syncs</p>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                    <div class="mt-5">{{ $sources->links() }}</div>
                @endif
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
