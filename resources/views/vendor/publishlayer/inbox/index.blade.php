<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PublishLayer Inbox</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900">
<div class="mx-auto max-w-6xl px-4 py-8">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">PublishLayer Inbox</h1>
            <p class="text-sm text-slate-500">Site: {{ $siteKey }}</p>
        </div>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
            {{ session('status') }}
        </div>
    @endif

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-100">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Title</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Updated</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-600">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            @forelse ($drafts as $draft)
                <tr>
                    <td class="px-4 py-3 text-sm">
                        <div class="font-medium text-slate-900">{{ $draft->title ?: 'Untitled Draft' }}</div>
                        <div class="text-xs text-slate-500">{{ $draft->pl_draft_id }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm">
                        <span class="inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700">
                            {{ $draft->status }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600">{{ optional($draft->updated_at)->diffForHumans() }}</td>
                    <td class="px-4 py-3 text-right text-sm">
                        <a href="{{ route('publishlayer-inbox.portal.show', ['draft' => $draft->id, 'site_key' => $siteKey]) }}" class="font-medium text-blue-700 hover:text-blue-900">View</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="px-4 py-8 text-center text-sm text-slate-500">No drafts received yet.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">
        {{ $drafts->links() }}
    </div>
</div>
</body>
</html>
