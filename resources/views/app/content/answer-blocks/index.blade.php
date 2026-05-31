<x-app.layout title="Answer Blocks | Argusly">
    <div class="mx-auto max-w-7xl">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Argusly Content Engine</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Answer Blocks</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Reusable answer-ready blocks for FAQs, direct answers, comparisons and future AI visibility surfaces.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.badge variant="blue">{{ $answerBlocks->total() }} blocks</x-ui.badge>
                @can('create', \App\Models\AnswerBlock::class)
                    <x-ui.button href="{{ route('app.content.answer-blocks.create') }}">Create block</x-ui.button>
                @endcan
            </div>
        </div>

        <x-ui.card class="mt-8 p-4">
            <form method="GET" action="{{ route('app.content.answer-blocks.index') }}" class="grid gap-3 md:grid-cols-[1fr_1fr_auto]">
                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Status</span>
                    <select name="status" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">All statuses</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ str($status)->headline() }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Type</span>
                    <select name="type" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">All types</option>
                        @foreach ($types as $type)
                            <option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ str($type)->replace('_', ' ')->headline() }}</option>
                        @endforeach
                    </select>
                </label>

                <div class="flex items-end gap-2">
                    <x-ui.button type="submit">Filter</x-ui.button>
                    <x-ui.button href="{{ route('app.content.answer-blocks.index') }}" variant="light">Reset</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <div class="mt-6 space-y-4">
            @forelse ($answerBlocks as $answerBlock)
                <a href="{{ route('app.content.answer-blocks.show', $answerBlock) }}" class="block rounded-2xl border border-line bg-white p-5 transition hover:bg-panel">
                    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-base font-semibold text-ink">{{ $answerBlock->question }}</h2>
                                <x-ui.badge>{{ str($answerBlock->type)->replace('_', ' ')->headline() }}</x-ui.badge>
                            </div>
                            <p class="mt-2 text-sm leading-6 text-muted">{{ str($answerBlock->answer)->limit(220) }}</p>
                            <p class="mt-2 text-xs text-muted">{{ $answerBlock->contentAsset?->title ?? 'Standalone block' }}</p>
                        </div>
                        <x-ui.badge :variant="$answerBlock->status === 'published' || $answerBlock->status === 'approved' ? 'success' : 'default'">{{ str($answerBlock->status)->headline() }}</x-ui.badge>
                    </div>
                </a>
            @empty
                <x-dashboard.empty-state title="No answer blocks yet" message="Create an answer block to prepare reusable answer-ready content." />
            @endforelse
        </div>

        <div class="mt-6">
            {{ $answerBlocks->links() }}
        </div>
    </div>
</x-app.layout>
