<x-app.layout title="Admin Contact Requests" :show-workspace-header="false">
    @include('admin._nav')

    <div class="mb-6 flex flex-col gap-2">
        <p class="text-sm font-semibold uppercase tracking-[0.12em] text-muted">Administration</p>
        <h1 class="text-3xl font-bold text-ink">Contact Requests</h1>
        <p class="max-w-3xl text-sm text-muted">Review messages submitted from the public contact form, triage low-quality leads, and track follow-up status.</p>
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

    @if ($requests)
        <div class="mt-6 space-y-4">
            @forelse ($requests as $contactRequest)
                @php
                    $metadata = json_decode((string) $contactRequest->metadata, true) ?: [];
                    $signals = $metadata['lead_signals'] ?? [];
                    $suggestedReply = $metadata['suggested_reply'] ?? "Hi,\n\nThanks for reaching out. Could you share a little more about your company, website, and what you want to solve with Argusly?\n\nBest,\nArgusly";
                    $replySubject = 'Re: Argusly contact request';
                    $replyUrl = 'https://mail.google.com/mail/?'.http_build_query([
                        'view' => 'cm',
                        'fs' => '1',
                        'to' => $contactRequest->email,
                        'su' => $replySubject,
                        'body' => $suggestedReply,
                    ], '', '&', PHP_QUERY_RFC3986);
                    $topicLabel = [
                        'pilot' => 'Pilot request',
                        'sales' => 'Sales conversation',
                        'support' => 'Support',
                        'partnership' => 'Partnership',
                        'press' => 'Press',
                        'other' => 'Other',
                    ][$contactRequest->topic] ?? str($contactRequest->topic)->headline();
                @endphp

                <article class="rounded-md border border-line bg-white p-5">
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-xl font-bold text-ink">{{ $contactRequest->name }}</h2>
                                @include('admin._status', ['value' => $contactRequest->status])
                                @if (($metadata['lead_quality'] ?? null) || ($metadata['lead_score'] ?? null) !== null)
                                    <span class="inline-flex rounded-full border border-line bg-panel px-2 py-0.5 text-xs font-semibold text-muted">
                                        {{ $metadata['lead_quality'] ?? 'Lead' }} - {{ $metadata['lead_score'] ?? 'n/a' }}/100
                                    </span>
                                @endif
                            </div>
                            <p class="mt-2 text-sm text-muted">
                                <a href="mailto:{{ $contactRequest->email }}" class="font-semibold text-ink hover:underline">{{ $contactRequest->email }}</a>
                                - {{ $contactRequest->company ?: 'No company' }}
                            </p>
                            <p class="mt-1 text-sm text-muted">
                                {{ $topicLabel }} - Submitted {{ \Illuminate\Support\Carbon::parse($contactRequest->created_at)->format('Y-m-d H:i') }}
                                @if ($contactRequest->handled_at)
                                    - Handled {{ \Illuminate\Support\Carbon::parse($contactRequest->handled_at)->format('Y-m-d H:i') }}
                                @endif
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <a href="{{ $replyUrl }}" target="_blank" rel="noreferrer" class="rounded-md border border-line px-3 py-2 text-sm font-semibold text-ink transition hover:bg-panel">Reply</a>
                            @if ($contactRequest->website)
                                <a href="{{ $contactRequest->website }}" target="_blank" rel="noreferrer" class="rounded-md border border-line px-3 py-2 text-sm font-semibold text-ink transition hover:bg-panel">Open website</a>
                            @endif
                            <a href="{{ route('admin.accounts') }}?q={{ urlencode((string) $contactRequest->company) }}" class="rounded-md border border-line px-3 py-2 text-sm font-semibold text-ink transition hover:bg-panel">Find account</a>
                        </div>
                    </div>

                    <div class="mt-5 grid gap-4 xl:grid-cols-[1fr_320px]">
                        <div class="space-y-4">
                            <div class="rounded-md border border-line bg-panel/40 p-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Message</p>
                                <p class="mt-2 whitespace-pre-line text-sm leading-6 text-ink">{{ $contactRequest->message }}</p>
                            </div>

                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="rounded-md border border-line p-4">
                                    <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Website</p>
                                    <p class="mt-2 text-sm font-semibold text-ink">
                                        @if ($contactRequest->website)
                                            <a href="{{ $contactRequest->website }}" class="hover:underline" target="_blank" rel="noreferrer">{{ $contactRequest->website }}</a>
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

                            <div class="rounded-md border border-line p-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Lead signals</p>
                                @if ($signals)
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        @foreach ($signals as $signal)
                                            <span class="rounded-full border border-line bg-panel px-2 py-1 text-xs font-semibold text-muted">{{ $signal }}</span>
                                        @endforeach
                                    </div>
                                @else
                                    <p class="mt-2 text-sm text-muted">No risk signals recorded.</p>
                                @endif
                            </div>
                        </div>

                        <div class="rounded-md border border-line p-4">
                            <h3 class="text-sm font-bold text-ink">Review actions</h3>
                            <div class="mt-4 grid gap-2">
                                @foreach ([
                                    'reviewing' => 'Mark reviewing',
                                    'contacted' => 'Mark contacted',
                                    'unqualified' => 'Mark unqualified',
                                    'spam' => 'Mark spam',
                                    'closed' => 'Close request',
                                    'new' => 'Reset new',
                                ] as $status => $label)
                                    <form method="POST" action="{{ route('admin.contact-requests.update', $contactRequest->id) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="status" value="{{ $status }}">
                                        <button type="submit" @class([
                                            'w-full rounded-md border px-3 py-2 text-left text-sm font-semibold transition',
                                            'border-red-200 bg-red-50 text-red-800 hover:bg-red-100' => in_array($status, ['unqualified', 'spam'], true),
                                            'border-slate-200 bg-slate-50 text-slate-800 hover:bg-slate-100' => $status === 'closed',
                                            'border-line text-ink hover:bg-panel' => ! in_array($status, ['unqualified', 'spam', 'closed'], true),
                                        ])>{{ $label }}</button>
                                    </form>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </article>
            @empty
                <div class="rounded-md border border-line bg-white p-8 text-center">
                    <h2 class="text-lg font-bold text-ink">No contact requests yet</h2>
                    <p class="mt-2 text-sm text-muted">New messages submitted from the public contact page will appear here.</p>
                </div>
            @endforelse
        </div>

        <div class="mt-6">{{ $requests->links() }}</div>
    @else
        <div class="mt-6 rounded-md border border-line bg-white p-6">
            <h2 class="text-lg font-bold text-ink">Contact request storage is not ready</h2>
            <p class="mt-2 text-sm text-muted">The `contact_requests` table does not exist yet. Run the migrations before accepting contact requests.</p>
        </div>
    @endif
</x-app.layout>
