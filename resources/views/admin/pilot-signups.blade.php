<x-app.layout title="Admin Pilot Signups" :show-workspace-header="false">
    @include('admin._nav')

    <div class="mb-6 flex flex-col gap-2">
        <p class="text-sm font-semibold uppercase tracking-[0.12em] text-muted">Administration</p>
        <h1 class="text-3xl font-bold text-ink">Pilot Requests</h1>
        <p class="max-w-3xl text-sm text-muted">Review incoming pilot requests, follow up with the requester, and move approved pilots into account setup.</p>
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ session('status') }}</div>
    @endif

    <div class="grid gap-4 md:grid-cols-3 xl:grid-cols-6">
        @foreach ($stats as $label => $value)
            <div class="rounded-md border border-line bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-[0.08em] text-muted">{{ str($label)->headline() }}</p>
                <p class="mt-3 text-3xl font-bold text-ink">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    @if ($signups)
        <div class="mt-6 space-y-4">
            @forelse ($signups as $signup)
                @php
                    $metadata = json_decode((string) $signup->metadata, true) ?: [];
                    $mailtoSubject = rawurlencode('Argusly pilot request');
                    $mailtoBody = rawurlencode("Hi {$signup->name},\n\nThanks for requesting an Argusly pilot. I reviewed your request and would like to schedule the next step.\n\nBest,\nArgusly");
                @endphp

                <article class="rounded-md border border-line bg-white p-5">
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-xl font-bold text-ink">{{ $signup->company }}</h2>
                                @include('admin._status', ['value' => $signup->status])
                            </div>
                            <p class="mt-2 text-sm text-muted">
                                {{ $signup->name }} · <a href="mailto:{{ $signup->email }}" class="font-semibold text-ink hover:underline">{{ $signup->email }}</a>
                                @if ($signup->role)
                                    · {{ $signup->role }}
                                @endif
                            </p>
                            <p class="mt-1 text-sm text-muted">
                                Submitted {{ \Illuminate\Support\Carbon::parse($signup->created_at)->format('Y-m-d H:i') }}
                                @if ($signup->reviewed_at)
                                    · Reviewed {{ \Illuminate\Support\Carbon::parse($signup->reviewed_at)->format('Y-m-d H:i') }}
                                @endif
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <a href="mailto:{{ $signup->email }}?subject={{ $mailtoSubject }}&body={{ $mailtoBody }}" class="rounded-md border border-line px-3 py-2 text-sm font-semibold text-ink transition hover:bg-panel">Send follow-up</a>
                            <a href="{{ route('admin.accounts') }}?q={{ urlencode($signup->company) }}" class="rounded-md border border-line px-3 py-2 text-sm font-semibold text-ink transition hover:bg-panel">Find account</a>
                            <a href="{{ route('admin.accounts') }}" class="rounded-md border border-line px-3 py-2 text-sm font-semibold text-ink transition hover:bg-panel">Create account</a>
                        </div>
                    </div>

                    <div class="mt-5 grid gap-4 xl:grid-cols-[1fr_320px]">
                        <div class="space-y-4">
                            <div class="rounded-md border border-line bg-panel/40 p-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Goal</p>
                                <p class="mt-2 text-sm leading-6 text-ink">{{ $signup->goal ?: 'No goal provided.' }}</p>
                            </div>

                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="rounded-md border border-line p-4">
                                    <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Website</p>
                                    <p class="mt-2 text-sm font-semibold text-ink">
                                        @if ($signup->website)
                                            <a href="{{ $signup->website }}" class="hover:underline" target="_blank" rel="noreferrer">{{ $signup->website }}</a>
                                        @else
                                            Not provided
                                        @endif
                                    </p>
                                </div>
                                <div class="rounded-md border border-line p-4">
                                    <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Source</p>
                                    <p class="mt-2 text-sm font-semibold text-ink">{{ $metadata['source'] ?? 'unknown' }}</p>
                                    <p class="mt-1 text-xs text-muted">{{ $metadata['ip'] ?? 'no ip' }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-md border border-line p-4">
                            <h3 class="text-sm font-bold text-ink">Review actions</h3>
                            <div class="mt-4 grid gap-2">
                                @foreach ([
                                    'reviewing' => 'Mark reviewing',
                                    'contacted' => 'Mark contacted',
                                    'activated' => 'Activate pilot',
                                    'declined' => 'Decline',
                                    'pending' => 'Reset pending',
                                ] as $status => $label)
                                    <form method="POST" action="{{ route('admin.pilot-signups.update', $signup->id) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="status" value="{{ $status }}">
                                        <button type="submit" @class([
                                            'w-full rounded-md border px-3 py-2 text-left text-sm font-semibold transition',
                                            'border-emerald-200 bg-emerald-50 text-emerald-800 hover:bg-emerald-100' => $status === 'activated',
                                            'border-red-200 bg-red-50 text-red-800 hover:bg-red-100' => $status === 'declined',
                                            'border-line text-ink hover:bg-panel' => ! in_array($status, ['activated', 'declined'], true),
                                        ])>{{ $label }}</button>
                                    </form>
                                @endforeach
                            </div>

                            <div class="mt-5 border-t border-line pt-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Next setup</p>
                                <div class="mt-3 grid gap-2">
                                    <a href="{{ route('admin.brands') }}" class="text-sm font-semibold text-ink hover:underline">Create brand</a>
                                    <a href="{{ route('admin.users') }}" class="text-sm font-semibold text-ink hover:underline">Create or assign user</a>
                                    <a href="{{ route('admin.modules') }}" class="text-sm font-semibold text-ink hover:underline">Enable modules</a>
                                    <a href="{{ route('admin.credits') }}" class="text-sm font-semibold text-ink hover:underline">Grant pilot credits</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </article>
            @empty
                <div class="rounded-md border border-line bg-white p-8 text-center">
                    <h2 class="text-lg font-bold text-ink">No pilot requests yet</h2>
                    <p class="mt-2 text-sm text-muted">New requests submitted from the public signup page will appear here.</p>
                </div>
            @endforelse
        </div>

        <div class="mt-6">{{ $signups->links() }}</div>
    @else
        <div class="mt-6 rounded-md border border-line bg-white p-6">
            <h2 class="text-lg font-bold text-ink">Pilot request storage is not ready</h2>
            <p class="mt-2 text-sm text-muted">The `pilot_signups` table does not exist yet. Run the migrations before accepting pilot requests.</p>
        </div>
    @endif
</x-app.layout>
