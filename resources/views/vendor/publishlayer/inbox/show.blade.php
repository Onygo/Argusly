<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $draft->title ?: 'Draft' }} - PublishLayer Inbox</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900">
<div class="mx-auto max-w-4xl px-4 py-8">
    <div class="mb-5 flex items-center justify-between">
        <a href="{{ route('publishlayer-inbox.portal.index', ['site_key' => $siteKey]) }}" class="text-sm font-medium text-blue-700 hover:text-blue-900">Back to Inbox</a>
        <span class="inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700">{{ $draft->status }}</span>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
            {{ session('status') }}
        </div>
    @endif

    <h1 class="mb-2 text-3xl font-semibold">{{ $draft->title ?: 'Untitled Draft' }}</h1>
    <p class="mb-6 text-sm text-slate-500">Draft ID: {{ $draft->pl_draft_id }}</p>

    @if ($draft->featured_image_path)
        <img src="{{ \Illuminate\Support\Facades\Storage::disk(config('publishlayer_connector.images.disk', 'public'))->url($draft->featured_image_path) }}" alt="Featured image" class="mb-6 w-full rounded-lg border border-slate-200 object-cover">
    @endif

    @if ($draft->excerpt)
        <p class="mb-6 rounded-md border border-slate-200 bg-white p-4 text-sm text-slate-700">{{ $draft->excerpt }}</p>
    @endif

    <article class="prose prose-slate max-w-none rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
        {!! $renderedBodyHtml !!}
    </article>

    <div class="mt-6 flex flex-wrap gap-3">
        <form method="POST" action="{{ route('publishlayer-inbox.portal.approve', ['draft' => $draft->id, 'site_key' => $siteKey]) }}">
            @csrf
            <button type="submit" class="rounded-md bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-900">Approve</button>
        </form>

        <form method="POST" action="{{ route('publishlayer-inbox.portal.publish', ['draft' => $draft->id, 'site_key' => $siteKey]) }}">
            @csrf
            <button type="submit" class="rounded-md bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800">Publish</button>
        </form>

        @if ($draft->publicUrl())
            <a href="{{ $draft->publicUrl() }}" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">Open Public URL</a>
        @endif
    </div>
</div>
</body>
</html>
