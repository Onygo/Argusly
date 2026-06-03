<x-app.layout :title="__('content.create_content').' | Argusly'">
    <div class="w-full">
        <div>
            <p class="eyebrow">Argusly Content Engine</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ __('content.create_content') }}</h1>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Create a manual placeholder asset. Generation workflows will connect to this foundation later.</p>
        </div>

        <form method="POST" action="{{ route('app.content.store') }}" class="mt-8">
            @include('app.content._form')
        </form>
    </div>
</x-app.layout>
