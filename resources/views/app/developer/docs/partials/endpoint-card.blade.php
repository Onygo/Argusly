@php
    $methodColors = [
        'get' => 'bg-emerald-100 text-emerald-700 border-emerald-200',
        'post' => 'bg-blue-100 text-blue-700 border-blue-200',
        'put' => 'bg-amber-100 text-amber-700 border-amber-200',
        'patch' => 'bg-amber-100 text-amber-700 border-amber-200',
        'delete' => 'bg-rose-100 text-rose-700 border-rose-200',
    ];
    $methodColor = $methodColors[$endpoint['method']] ?? 'bg-gray-100 text-gray-700 border-gray-200';
@endphp

<div class="rounded-lg border border-border bg-background" x-data="{ open: false }">
    {{-- Header --}}
    <button @click="open = !open" class="flex w-full items-start justify-between gap-4 p-4 text-left">
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center rounded border px-2 py-0.5 text-xs font-semibold uppercase {{ $methodColor }}">
                    {{ strtoupper($endpoint['method']) }}
                </span>
                <code class="break-all text-sm font-medium text-textPrimary">{{ $endpoint['path'] }}</code>
            </div>
            <p class="mt-1 text-sm text-textSecondary">{{ $endpoint['summary'] }}</p>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            @if (!empty($endpoint['scopes']))
                <span class="rounded-full border border-border px-2 py-0.5 text-xs text-textSecondary">
                    {{ $endpoint['scopes'][0] ?? '' }}
                </span>
            @endif
            <svg class="h-5 w-5 text-textSecondary transition-transform" :class="{ 'rotate-180': open }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </div>
    </button>

    {{-- Expanded Content --}}
    <div x-show="open" x-collapse class="border-t border-border">
        <div class="space-y-4 p-4">
            @if ($endpoint['description'])
                <div>
                    <p class="text-sm text-textSecondary">{{ $endpoint['description'] }}</p>
                </div>
            @endif

            {{-- Scopes --}}
            @if (!empty($endpoint['scopes']))
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Required Scopes</p>
                    <div class="mt-1 flex flex-wrap gap-1">
                        @foreach ($endpoint['scopes'] as $scope)
                            <span class="rounded border border-border bg-surfaceSubtle px-2 py-0.5 font-mono text-xs text-textPrimary">{{ $scope }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Parameters --}}
            @if (!empty($endpoint['parameters']))
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Parameters</p>
                    <div class="mt-2 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-border text-left text-xs text-textSecondary">
                                    <th class="pb-2 pr-4">Name</th>
                                    <th class="pb-2 pr-4">In</th>
                                    <th class="pb-2 pr-4">Type</th>
                                    <th class="pb-2 pr-4">Required</th>
                                    <th class="pb-2">Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($endpoint['parameters'] as $param)
                                    <tr class="border-b border-border/50">
                                        <td class="py-2 pr-4 font-mono text-xs text-textPrimary">{{ $param['name'] }}</td>
                                        <td class="py-2 pr-4 text-xs text-textSecondary">{{ $param['in'] }}</td>
                                        <td class="py-2 pr-4 text-xs text-textSecondary">{{ $param['schema']['type'] ?? 'string' }}</td>
                                        <td class="py-2 pr-4">
                                            @if ($param['required'] ?? false)
                                                <span class="text-xs text-rose-600">required</span>
                                            @else
                                                <span class="text-xs text-textSecondary">optional</span>
                                            @endif
                                        </td>
                                        <td class="py-2 text-xs text-textSecondary">{{ $param['description'] ?? '' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- Request Body --}}
            @if (!empty($endpoint['requestBody']))
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Request Body</p>
                    @if (!empty($endpoint['requestBody']['schema']['properties']))
                        <div class="mt-2">
                            @include('app.developer.docs.partials.schema-viewer', ['schema' => $endpoint['requestBody']['schema'], 'compact' => true])
                        </div>
                    @endif
                    @if (!empty($endpoint['requestBody']['exampleJson']))
                        <div class="mt-2">
                            <p class="mb-1 text-xs text-textSecondary">Example:</p>
                            <pre class="overflow-x-auto rounded-md border border-border bg-surfaceSubtle p-3 text-xs"><code>{{ $endpoint['requestBody']['exampleJson'] }}</code></pre>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Responses --}}
            @if (!empty($endpoint['responses']))
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Responses</p>
                    <div class="mt-2 space-y-2">
                        @foreach ($endpoint['responses'] as $code => $response)
                            <div class="rounded border border-border bg-surfaceSubtle p-3">
                                <div class="flex items-center gap-2">
                                    <span class="rounded px-1.5 py-0.5 text-xs font-semibold {{ $code >= 200 && $code < 300 ? 'bg-emerald-100 text-emerald-700' : ($code >= 400 ? 'bg-rose-100 text-rose-700' : 'bg-gray-100 text-gray-700') }}">
                                        {{ $code }}
                                    </span>
                                    <span class="text-xs text-textSecondary">{{ $response['description'] }}</span>
                                </div>
                                @if (!empty($response['exampleJson']) && $code >= 200 && $code < 300)
                                    <pre class="mt-2 overflow-x-auto rounded border border-border bg-background p-2 text-xs"><code>{{ $response['exampleJson'] }}</code></pre>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
