<x-app.layout title="LLM Providers" :show-workspace-header="false">
    @include('admin._nav')

    <div class="mt-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold text-ink">LLM providers</h1>
                <p class="text-sm text-muted">Provider credentials are read from env variables and are not shown here.</p>
            </div>
            <a href="{{ route('admin.llm') }}" class="rounded-md border border-line px-3 py-2 text-sm font-semibold text-ink">Defaults</a>
        </div>

        @if (session('status'))
            <p class="mt-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ session('status') }}</p>
        @endif

        <div class="mt-4 overflow-hidden rounded-md border border-line bg-white">
            <table class="min-w-full divide-y divide-line text-left text-sm">
                <thead class="bg-panel text-xs font-semibold uppercase tracking-[0.08em] text-muted">
                    <tr>
                        <th class="px-4 py-3">Provider</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Base URL</th>
                        <th class="px-4 py-3">API key env</th>
                        <th class="px-4 py-3">Models</th>
                        <th class="px-4 py-3">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($providers as $provider)
                        <tr>
                            <td class="px-4 py-3 font-semibold text-ink">{{ $provider->name }}<p class="text-xs text-muted">{{ $provider->provider }}</p></td>
                            <td class="px-4 py-3">@include('admin._status', ['value' => $provider->status])</td>
                            <td class="max-w-xs px-4 py-3 text-muted">{{ $provider->base_url ?: 'n/a' }}</td>
                            <td class="px-4 py-3">
                                <span class="font-mono text-xs text-ink">{{ $provider->api_key_env ?: 'n/a' }}</span>
                                @if ($provider->api_key_env)
                                    <p class="mt-1">@include('admin._status', ['value' => filled(env($provider->api_key_env)) ? 'configured' : 'missing'])</p>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-ink">{{ $provider->models_count }}</td>
                            <td class="px-4 py-3">
                                <form method="POST" action="{{ route('admin.llm.providers.update', $provider) }}" class="flex gap-2">
                                    @csrf
                                    @method('PATCH')
                                    <select name="status" class="rounded-md border border-line px-2 py-1 text-sm">
                                        @foreach (['active', 'inactive', 'archived'] as $status)
                                            <option value="{{ $status }}" @selected($provider->status === $status)>{{ str($status)->headline() }}</option>
                                        @endforeach
                                    </select>
                                    <button class="rounded-md bg-ink px-3 py-1 text-sm font-semibold text-white">Save</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-app.layout>
