<x-app.layout title="LLM Models" :show-workspace-header="false">
    @include('admin._nav')

    <div class="mt-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold text-ink">LLM models</h1>
                <p class="text-sm text-muted">Enable, disable or archive models available for resolver selection.</p>
            </div>
            <a href="{{ route('admin.llm.providers') }}" class="rounded-md border border-line px-3 py-2 text-sm font-semibold text-ink">Providers</a>
        </div>

        @if (session('status'))
            <p class="mt-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ session('status') }}</p>
        @endif

        <form method="GET" action="{{ route('admin.llm.models') }}" class="mt-4 grid gap-3 rounded-md border border-line bg-white p-4 md:grid-cols-4">
            <select name="provider_id" class="rounded-md border border-line px-3 py-2 text-sm">
                <option value="">All providers</option>
                @foreach ($providers as $provider)
                    <option value="{{ $provider->id }}" @selected((int) request('provider_id') === $provider->id)>{{ $provider->name }}</option>
                @endforeach
            </select>
            <select name="type" class="rounded-md border border-line px-3 py-2 text-sm">
                <option value="">All types</option>
                @foreach ($types as $type)
                    <option value="{{ $type }}" @selected(request('type') === $type)>{{ str($type)->headline() }}</option>
                @endforeach
            </select>
            <select name="status" class="rounded-md border border-line px-3 py-2 text-sm">
                <option value="">All statuses</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ str($status)->headline() }}</option>
                @endforeach
            </select>
            <button class="rounded-md bg-ink px-4 py-2 text-sm font-semibold text-white">Filter</button>
        </form>

        <div class="mt-4 overflow-hidden rounded-md border border-line bg-white">
            <table class="min-w-full divide-y divide-line text-left text-sm">
                <thead class="bg-panel text-xs font-semibold uppercase tracking-[0.08em] text-muted">
                    <tr>
                        <th class="px-4 py-3">Model</th>
                        <th class="px-4 py-3">Provider</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">Capabilities</th>
                        <th class="px-4 py-3">Context</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($models as $model)
                        <tr>
                            <td class="px-4 py-3 font-semibold text-ink">{{ $model->name }}<p class="font-mono text-xs text-muted">{{ $model->model }}</p></td>
                            <td class="px-4 py-3 text-ink">{{ $model->provider?->name }}</td>
                            <td class="px-4 py-3">{{ str($model->type)->headline() }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    @foreach (['json' => $model->supports_json, 'tools' => $model->supports_tools, 'vision' => $model->supports_vision, 'streaming' => $model->supports_streaming] as $label => $enabled)
                                        @if ($enabled)
                                            <span class="rounded-full border border-blue/20 bg-blue/10 px-2 py-0.5 text-xs font-semibold text-blue">{{ $label }}</span>
                                        @endif
                                    @endforeach
                                </div>
                            </td>
                            <td class="px-4 py-3 text-muted">{{ $model->context_window ? number_format($model->context_window) : 'n/a' }}</td>
                            <td class="px-4 py-3">@include('admin._status', ['value' => $model->status])</td>
                            <td class="px-4 py-3">
                                <form method="POST" action="{{ route('admin.llm.models.update', $model) }}" class="flex gap-2">
                                    @csrf
                                    @method('PATCH')
                                    <select name="status" class="rounded-md border border-line px-2 py-1 text-sm">
                                        @foreach ($statuses as $status)
                                            <option value="{{ $status }}" @selected($model->status === $status)>{{ str($status)->headline() }}</option>
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

        <div class="mt-4">{{ $models->links() }}</div>
    </div>
</x-app.layout>
