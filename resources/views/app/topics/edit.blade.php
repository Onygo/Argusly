<x-app.layout title="Edit Topic | Argusly">
    <div class="mx-auto max-w-3xl">
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
            <div>
                <p class="eyebrow">Topic intelligence</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Edit {{ $topic->name }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Keep topic naming, scope and prioritization clean for downstream intelligence workflows.</p>
            </div>
            <x-ui.button href="{{ route('app.topics.show', $topic) }}" variant="secondary">Back</x-ui.button>
        </div>

        <x-dashboard.section title="Topic" class="mt-8">
            <form method="POST" action="{{ route('app.topics.update', $topic) }}" class="space-y-5">
                @csrf
                @method('PUT')
                @include('app.topics._form', ['topic' => $topic, 'statuses' => $statuses])
                <div class="flex flex-wrap items-center gap-3">
                    <x-ui.button type="submit">Save topic</x-ui.button>
                </div>
            </form>
        </x-dashboard.section>
    </div>
</x-app.layout>
