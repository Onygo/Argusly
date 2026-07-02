@extends('layouts.admin', ['title' => 'Contact Submissions'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Contact Submissions</x-slot:title>
        <x-slot:description>Read-only overview of public contact form submissions.</x-slot:description>
    </x-page-header>
@endsection

@section('content')

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    @if ($errors->has('contact'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first('contact') }}</div>
    @endif

    <x-data-table label="Contact submissions" description="Public contact form submissions with delivery status, message details, and resend action." table-class="min-w-[1100px]">
        <x-data-table.header>
            <x-data-table.row>
                <x-data-table.cell heading>Received</x-data-table.cell>
                <x-data-table.cell heading>Name</x-data-table.cell>
                <x-data-table.cell heading>Email</x-data-table.cell>
                <x-data-table.cell heading>Interest</x-data-table.cell>
                <x-data-table.cell heading>Topic</x-data-table.cell>
                <x-data-table.cell heading>Source</x-data-table.cell>
                <x-data-table.cell heading>Subject</x-data-table.cell>
                <x-data-table.cell heading>Mail</x-data-table.cell>
                <x-data-table.cell heading>Actions</x-data-table.cell>
            </x-data-table.row>
        </x-data-table.header>
        <tbody>
            @forelse ($submissions as $submission)
                <x-data-table.row>
                    <x-data-table.cell label="Received">{{ optional($submission->created_at)->format('Y-m-d H:i') }}</x-data-table.cell>
                    <x-data-table.cell label="Name">{{ $submission->name }}</x-data-table.cell>
                    <x-data-table.cell label="Email">{{ $submission->email }}</x-data-table.cell>
                    <x-data-table.cell label="Interest">{{ $submission->interest_area ?: 'n/a' }}</x-data-table.cell>
                    <x-data-table.cell label="Topic">{{ $submission->topic ?: 'n/a' }}</x-data-table.cell>
                    <x-data-table.cell label="Source">{{ $submission->source_page ?: 'n/a' }}</x-data-table.cell>
                    <x-data-table.cell label="Subject">{{ $submission->subject ?: 'n/a' }}</x-data-table.cell>
                    <x-data-table.cell label="Mail">
                        @if ($submission->mail_sent_at)
                            <x-data-table.badge tone="success" label="sent" />
                        @elseif ($submission->mail_error)
                            <x-data-table.badge tone="danger" label="failed" />
                        @else
                            <x-data-table.badge label="pending" />
                        @endif
                    </x-data-table.cell>
                    <x-data-table.cell label="Actions">
                        <x-data-table.actions align="start">
                            <form method="POST" action="{{ route('admin.contact-submissions.resend', $submission) }}">
                                @csrf
                                <button class="rounded border border-border px-2 py-1 text-xs">Resend</button>
                            </form>
                        </x-data-table.actions>
                    </x-data-table.cell>
                </x-data-table.row>
                <x-data-table.row>
                    <x-data-table.cell class="pb-3 text-xs text-textSecondary" colspan="9">
                        @if ($submission->website || $submission->market || $submission->competitors || $submission->growth_goal)
                            <div class="mb-2 grid gap-1 sm:grid-cols-2">
                                <span><strong class="text-textPrimary">Website:</strong> {{ $submission->website ?: 'n/a' }}</span>
                                <span><strong class="text-textPrimary">Market:</strong> {{ $submission->market ?: 'n/a' }}</span>
                                <span><strong class="text-textPrimary">Competitors:</strong> {{ \Illuminate\Support\Str::limit($submission->competitors ?: 'n/a', 120) }}</span>
                                <span><strong class="text-textPrimary">Growth goal:</strong> {{ $submission->growth_goal ?: 'n/a' }}</span>
                            </div>
                        @endif
                        <strong class="text-textPrimary">Message:</strong>
                        {{ \Illuminate\Support\Str::limit($submission->message, 280) }}
                        @if ($submission->mail_error)
                            <br>
                            <strong class="text-textPrimary">Mail error:</strong>
                            {{ \Illuminate\Support\Str::limit($submission->mail_error, 260) }}
                        @endif
                    </x-data-table.cell>
                </x-data-table.row>
            @empty
                <x-data-table.empty colspan="9" title="No contact submissions found" />
            @endforelse
        </tbody>
        <x-slot:pagination>{{ $submissions->links() }}</x-slot:pagination>
    </x-data-table>
@endsection
