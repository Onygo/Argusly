@props(['title', 'description' => null, 'eyebrow' => 'Administration'])

<x-app.layout :title="$title.' | Argusly'">
    <div class="w-full">
        <div>
            <p class="eyebrow">{{ $eyebrow }}</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ $title }}</h1>
            @if ($description)
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">{{ $description }}</p>
            @endif
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-[260px_minmax(0,1fr)] lg:items-start">
            <x-settings.nav />

            <div class="min-w-0">
                @if (session('status'))
                    <div class="mb-5 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
                        {{ session('status') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="mb-5 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        <p class="font-semibold">Could not save changes</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{ $slot }}
            </div>
        </div>
    </div>
</x-app.layout>
