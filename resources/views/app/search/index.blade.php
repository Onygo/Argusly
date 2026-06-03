<x-app.layout title="Search | Argusly">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Workspace search</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Search results</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Find content, campaigns, contacts, organizations and topics inside the current tenant context.</p>
            </div>
            @if ($query !== '')
                <x-ui.badge variant="blue">{{ $total }} results</x-ui.badge>
            @endif
        </div>

        <x-ui.card class="mt-8 p-4">
            <form method="GET" action="{{ route('app.search') }}" class="grid gap-3 md:grid-cols-[1fr_auto]">
                <label class="block">
                    <span class="sr-only">Search query</span>
                    <input name="q" value="{{ $query }}" type="search" autofocus placeholder="Search content, campaigns, contacts, topics..." class="w-full rounded-md border border-line bg-white px-3 py-3 text-sm text-ink outline-none transition placeholder:text-muted focus:border-blue focus:ring-2 focus:ring-blue/10">
                </label>
                <x-ui.button type="submit" variant="dark">Search</x-ui.button>
            </form>
        </x-ui.card>

        @if ($query === '')
            <div class="mt-6">
                <x-dashboard.empty-state title="Start with a search" message="Search across the records connected to the current account and brand." />
            </div>
        @elseif ($total === 0)
            <div class="mt-6">
                <x-dashboard.empty-state title="No results found" message="Try a different word, brand term, campaign name or contact." />
            </div>
        @else
            <div class="mt-6 space-y-6">
                @include('app.search._section', [
                    'title' => 'Content',
                    'items' => $results['content'],
                    'empty' => 'No matching content assets.',
                    'route' => fn ($item) => route('app.content.show', $item),
                    'label' => fn ($item) => $item->title,
                    'meta' => fn ($item) => str($item->type)->replace('_', ' ')->headline().' · '.str($item->status)->headline().' · '.strtoupper($item->language),
                    'description' => fn ($item) => $item->excerpt ?: str($item->body)->limit(180),
                ])

                @include('app.search._section', [
                    'title' => 'Campaigns',
                    'items' => $results['campaigns'],
                    'empty' => 'No matching campaigns.',
                    'route' => fn ($item) => route('app.campaigns.show', $item),
                    'label' => fn ($item) => $item->name,
                    'meta' => fn ($item) => str($item->status)->headline(),
                    'description' => fn ($item) => $item->objective ?: $item->description,
                ])

                @include('app.search._section', [
                    'title' => 'Contacts',
                    'items' => $results['contacts'],
                    'empty' => 'No matching contacts.',
                    'route' => fn ($item) => route('app.relationships.contacts.show', $item),
                    'label' => fn ($item) => $item->display_name ?: trim($item->first_name.' '.$item->last_name),
                    'meta' => fn ($item) => $item->email ?: 'No email',
                    'description' => fn ($item) => $item->notes,
                ])

                @include('app.search._section', [
                    'title' => 'Organizations',
                    'items' => $results['organizations'],
                    'empty' => 'No matching organizations.',
                    'route' => fn ($item) => route('app.relationships.organizations.show', $item),
                    'label' => fn ($item) => $item->name,
                    'meta' => fn ($item) => $item->industry ?: 'Organization',
                    'description' => fn ($item) => $item->description ?: $item->website,
                ])

                @include('app.search._section', [
                    'title' => 'Topics',
                    'items' => $results['topics'],
                    'empty' => 'No matching topics.',
                    'route' => fn ($item) => route('app.topics.show', $item),
                    'label' => fn ($item) => $item->name,
                    'meta' => fn ($item) => str($item->status)->headline().($item->brand_id ? ' · Brand topic' : ' · Account topic'),
                    'description' => fn ($item) => $item->description,
                ])
            </div>
        @endif
    </div>
</x-app.layout>
