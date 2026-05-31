<x-app.layout :title="$answerBlock->question.' | Argusly'">
    <div class="mx-auto max-w-7xl">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
            <div>
                <p class="eyebrow">Argusly Answer Block</p>
                <h1 class="mt-2 max-w-3xl text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ $answerBlock->question }}</h1>
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <x-ui.badge variant="blue">{{ str($answerBlock->type)->replace('_', ' ')->headline() }}</x-ui.badge>
                    <x-ui.badge :variant="$answerBlock->status === 'published' || $answerBlock->status === 'approved' ? 'success' : 'default'">{{ str($answerBlock->status)->headline() }}</x-ui.badge>
                    <span class="text-sm text-muted">{{ $answerBlock->language }}</span>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.button href="{{ route('app.content.answer-blocks.index') }}" variant="secondary">Back</x-ui.button>
                @can('update', $answerBlock)
                    <x-ui.button href="{{ route('app.content.answer-blocks.edit', $answerBlock) }}" variant="secondary">Edit</x-ui.button>
                    <form method="POST" action="{{ route('app.content.answer-blocks.destroy', $answerBlock) }}">
                        @csrf
                        @method('DELETE')
                        <x-ui.button type="submit" variant="secondary">Archive</x-ui.button>
                    </form>
                @endcan
            </div>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
                {{ session('status') }}
            </div>
        @endif

        <div class="mt-8 grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
            <x-ui.card class="p-6">
                <div class="whitespace-pre-line text-sm leading-7 text-muted">{{ $answerBlock->answer }}</div>
            </x-ui.card>

            <x-ui.card class="p-5">
                <h2 class="text-sm font-semibold text-ink">Block details</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted">Content asset</dt>
                        <dd class="font-medium text-ink">
                            @if ($answerBlock->contentAsset)
                                <a href="{{ route('app.content.show', $answerBlock->contentAsset) }}" class="text-blue hover:underline">{{ $answerBlock->contentAsset->title }}</a>
                            @else
                                Standalone
                            @endif
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted">Position</dt>
                        <dd class="font-medium text-ink">{{ $answerBlock->position ?? 'Not set' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted">Updated</dt>
                        <dd class="font-medium text-ink">{{ $answerBlock->updated_at?->diffForHumans() }}</dd>
                    </div>
                </dl>
            </x-ui.card>
        </div>
    </div>
</x-app.layout>
