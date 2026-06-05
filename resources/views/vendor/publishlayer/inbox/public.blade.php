<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $draft->title ?: 'Content' }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-white text-slate-900">
<div class="mx-auto max-w-3xl px-4 py-10">
    <h1 class="mb-4 text-4xl font-semibold">{{ $draft->title ?: 'Untitled' }}</h1>

    @if ($draft->featured_image_path)
        <img src="{{ \Illuminate\Support\Facades\Storage::disk(config('publishlayer_connector.images.disk', 'public'))->url($draft->featured_image_path) }}" alt="Featured image" class="mb-8 w-full rounded-lg border border-slate-200 object-cover">
    @endif

    <article class="prose prose-slate max-w-none">
        {!! $renderedBodyHtml !!}
    </article>
</div>
</body>
</html>
