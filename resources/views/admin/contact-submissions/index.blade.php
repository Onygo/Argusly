@extends('layouts.admin', ['title' => 'Contact Submissions'])

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Contact Submissions</h1>
        <p class="mt-1 text-textSecondary">Read-only overview of public contact form submissions.</p>
    </div>

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    @if ($errors->has('contact'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first('contact') }}</div>
    @endif

    <div class="rounded-lg border border-border bg-surface p-4">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-textSecondary">
                    <th class="pb-2 font-medium">Received</th>
                    <th class="pb-2 font-medium">Name</th>
                    <th class="pb-2 font-medium">Email</th>
                    <th class="pb-2 font-medium">Topic</th>
                    <th class="pb-2 font-medium">Source</th>
                    <th class="pb-2 font-medium">Subject</th>
                    <th class="pb-2 font-medium">Mail</th>
                    <th class="pb-2 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($submissions as $submission)
                    <tr>
                        <td class="py-3">{{ optional($submission->created_at)->format('Y-m-d H:i') }}</td>
                        <td class="py-3">{{ $submission->name }}</td>
                        <td class="py-3">{{ $submission->email }}</td>
                        <td class="py-3">{{ $submission->topic ?: 'n/a' }}</td>
                        <td class="py-3">{{ $submission->source_page ?: 'n/a' }}</td>
                        <td class="py-3">{{ $submission->subject ?: 'n/a' }}</td>
                        <td class="py-3">
                            @if ($submission->mail_sent_at)
                                <span class="text-emerald-700">sent</span>
                            @elseif ($submission->mail_error)
                                <span class="text-rose-700">failed</span>
                            @else
                                <span class="text-textSecondary">pending</span>
                            @endif
                        </td>
                        <td class="py-3">
                            <form method="POST" action="{{ route('admin.contact-submissions.resend', $submission) }}">
                                @csrf
                                <button class="rounded border border-border px-2 py-1 text-xs">Resend</button>
                            </form>
                        </td>
                    </tr>
                    <tr>
                        <td class="pb-3 text-xs text-textSecondary" colspan="8">
                            <strong class="text-textPrimary">Message:</strong>
                            {{ \Illuminate\Support\Str::limit($submission->message, 280) }}
                            @if ($submission->mail_error)
                                <br>
                                <strong class="text-textPrimary">Mail error:</strong>
                                {{ \Illuminate\Support\Str::limit($submission->mail_error, 260) }}
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="py-6 text-center text-textSecondary" colspan="8">No contact submissions found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $submissions->links() }}</div>
@endsection
