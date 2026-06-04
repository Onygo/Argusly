<x-app.layout title="Admin LLM" :show-workspace-header="false">
    @include('admin._nav')

    <div class="mt-4 flex flex-col gap-4">
        <div>
            <h1 class="text-2xl font-bold text-ink">LLM settings</h1>
            <p class="text-sm text-muted">Configure global model defaults and inspect provider readiness. API keys stay in environment variables.</p>
        </div>

        @if (session('status'))
            <p class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ session('status') }}</p>
        @endif

        <div class="grid gap-4 xl:grid-cols-[1fr_360px]">
            <form method="POST" action="{{ route('admin.llm.update') }}" class="rounded-md border border-line bg-white p-4">
                @csrf
                @method('PATCH')
                <h2 class="text-lg font-bold text-ink">Global defaults</h2>
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <label class="block text-sm font-semibold text-ink">Default provider
                        <select name="default_provider_id" class="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm">
                            <option value="">Environment fallback</option>
                            @foreach ($providers as $provider)
                                <option value="{{ $provider->id }}" @selected($global?->default_provider_id === $provider->id)>{{ $provider->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm font-semibold text-ink">Default model
                        <select name="default_model_id" class="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm">
                            <option value="">First active model for provider</option>
                            @foreach ($providers as $provider)
                                <optgroup label="{{ $provider->name }}">
                                    @foreach ($models->where('provider_id', $provider->id) as $model)
                                        <option value="{{ $model->id }}" @selected($global?->default_model_id === $model->id)>{{ $model->name }} · {{ $model->model }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm font-semibold text-ink">Fallback provider
                        <select name="fallback_provider_id" class="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm">
                            <option value="">None</option>
                            @foreach ($providers as $provider)
                                <option value="{{ $provider->id }}" @selected($global?->fallback_provider_id === $provider->id)>{{ $provider->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm font-semibold text-ink">Fallback model
                        <select name="fallback_model_id" class="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm">
                            <option value="">None</option>
                            @foreach ($providers as $provider)
                                <optgroup label="{{ $provider->name }}">
                                    @foreach ($models->where('provider_id', $provider->id) as $model)
                                        <option value="{{ $model->id }}" @selected($global?->fallback_model_id === $model->id)>{{ $model->name }} · {{ $model->model }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm font-semibold text-ink">Temperature
                        <input name="temperature" type="number" step="0.01" min="0" max="2" value="{{ old('temperature', $global?->temperature) }}" class="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm">
                    </label>
                    <label class="block text-sm font-semibold text-ink">Max tokens
                        <input name="max_tokens" type="number" min="1" value="{{ old('max_tokens', $global?->max_tokens) }}" class="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm">
                    </label>
                </div>
                <button class="mt-4 rounded-md bg-blue px-4 py-2 text-sm font-semibold text-white">Save global defaults</button>
            </form>

            <section class="rounded-md border border-line bg-white p-4">
                <h2 class="text-lg font-bold text-ink">Catalog</h2>
                <div class="mt-4 space-y-3">
                    <a href="{{ route('admin.llm.providers') }}" class="block rounded-md border border-line px-3 py-2 text-sm font-semibold text-ink hover:bg-panel">Manage providers</a>
                    <a href="{{ route('admin.llm.models') }}" class="block rounded-md border border-line px-3 py-2 text-sm font-semibold text-ink hover:bg-panel">Manage models</a>
                    <a href="{{ route('admin.llm-requests') }}" class="block rounded-md border border-line px-3 py-2 text-sm font-semibold text-ink hover:bg-panel">Inspect requests</a>
                </div>
                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded-md bg-panel p-3">
                        <dt class="text-muted">Providers</dt>
                        <dd class="text-xl font-bold text-ink">{{ $providers->count() }}</dd>
                    </div>
                    <div class="rounded-md bg-panel p-3">
                        <dt class="text-muted">Active models</dt>
                        <dd class="text-xl font-bold text-ink">{{ $models->count() }}</dd>
                    </div>
                </dl>
            </section>
        </div>
    </div>
</x-app.layout>
