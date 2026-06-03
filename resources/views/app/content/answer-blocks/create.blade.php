<x-app.layout title="Create Answer Block | Argusly">
    <div class="w-full">
        <div>
            <p class="eyebrow">Argusly Content Engine</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Create Answer Block</h1>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Create a manual answer-ready block. AI generation will be connected later.</p>
        </div>

        <form method="POST" action="{{ $contentAsset ? route('app.content.answer-blocks.store-for-asset', $contentAsset) : route('app.content.answer-blocks.store') }}" class="mt-8">
            @include('app.content.answer-blocks._form')
        </form>
    </div>
</x-app.layout>
