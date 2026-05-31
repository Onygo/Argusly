<x-app.layout title="Create Topic | Argusly">
    <div class="mx-auto max-w-3xl">
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
            <div>
                <p class="eyebrow">Topic intelligence</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Create topic</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Add a first-class topic that future content, visibility, competitor, mention, recommendation and agent workflows can attach to.</p>
            </div>
            <x-ui.button href="{{ route('app.topics.index') }}" variant="secondary">Back</x-ui.button>
        </div>

        <x-dashboard.section title="Topic" class="mt-8">
            <form method="POST" action="{{ route('app.topics.store') }}" class="space-y-5">
                @csrf
                @include('app.topics._form', ['topic' => $topic, 'statuses' => $statuses])
                <x-ui.button type="submit">Create topic</x-ui.button>
            </form>
        </x-dashboard.section>
    </div>
</x-app.layout>
