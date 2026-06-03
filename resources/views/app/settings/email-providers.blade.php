<x-app.settings.layout title="Email providers" description="Configure placeholder email delivery providers for future newsletter sending. Test messages use the fake provider only.">
    @if (session('status'))
        <div class="mb-5 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-5 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <p class="font-semibold">Could not save email provider</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[0.8fr_1.2fr]">
        <x-dashboard.section title="Create provider" description="Store provider metadata and encrypted placeholder credentials.">
            <form method="POST" action="{{ route('settings.email-providers.store') }}" class="space-y-4">
                @csrf
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Scope</span>
                        <select name="scope" class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                            <option value="brand">{{ $brand?->name ?? 'Current brand' }}</option>
                            <option value="account">Account-wide</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Provider</span>
                        <select name="provider" class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                            @foreach ($providerTypes as $provider)
                                <option value="{{ $provider }}">{{ str($provider)->headline() }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Name</span>
                        <input name="name" required class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Primary newsletter provider">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Status</span>
                        <select name="status" class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                            @foreach ($statuses as $status)
                                <option value="{{ $status }}">{{ str($status)->headline() }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">From email</span>
                        <input name="from_email" type="email" class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="newsletter@example.com">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">From name</span>
                        <input name="from_name" class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Argusly">
                    </label>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Credential label</span>
                        <input name="credential_label" class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="API key">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Secret</span>
                        <input name="secret" type="password" class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    </label>
                </div>
                <x-ui.button type="submit">Create provider</x-ui.button>
            </form>
        </x-dashboard.section>

        <x-dashboard.section title="Configured providers" description="Fake test sends verify tenant wiring without calling provider APIs.">
            @if ($providers->isEmpty())
                <x-dashboard.empty-state title="No email providers" message="Create a provider placeholder before newsletter sending is implemented." />
            @else
                <div class="space-y-4">
                    @foreach ($providers as $provider)
                        <div class="rounded-md border border-line bg-panel p-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-semibold text-ink">{{ $provider->name }}</p>
                                <x-ui.badge>{{ str($provider->provider)->headline() }}</x-ui.badge>
                                <x-ui.badge variant="{{ $provider->status === 'active' ? 'success' : ($provider->status === 'failed' ? 'dark' : 'default') }}">{{ str($provider->status)->headline() }}</x-ui.badge>
                                <x-ui.badge>{{ $provider->brand?->name ?? 'Account-wide' }}</x-ui.badge>
                            </div>
                            <p class="mt-2 text-sm text-muted">{{ $provider->settings['from_email'] ?? 'No from email configured' }}</p>
                            <p class="mt-1 text-xs text-muted">Last verified {{ $provider->last_verified_at?->diffForHumans() ?? 'never' }}</p>

                            <form method="POST" action="{{ route('settings.email-providers.test', $provider) }}" class="mt-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                                @csrf
                                <label class="block">
                                    <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Test recipient</span>
                                    <input name="to" type="email" required class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="you@example.com">
                                </label>
                                <x-ui.button type="submit" variant="secondary">Send fake test</x-ui.button>
                            </form>
                        </div>
                    @endforeach
                </div>
                <div class="mt-5">{{ $providers->links() }}</div>
            @endif
        </x-dashboard.section>
    </div>
</x-app.settings.layout>
