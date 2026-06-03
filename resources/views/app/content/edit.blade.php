<x-app.layout title="Edit Content Asset | Argusly">
    <div class="w-full">
        <div>
            <p class="eyebrow">Argusly Content Engine</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Edit content asset</h1>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">{{ $asset->title }}</p>
        </div>

        <form method="POST" action="{{ route('app.content.update', $asset) }}" class="mt-8">
            @include('app.content._form')
        </form>
    </div>
</x-app.layout>
