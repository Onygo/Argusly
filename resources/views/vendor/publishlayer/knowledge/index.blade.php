@extends(config('publishlayer.layout', 'layouts.app'))

@section('title', $meta['title'])

@push('head')
    @include('publishlayer::knowledge.partials.head', ['meta' => $meta, 'structuredData' => $structuredData])
@endpush

@section('content')
    <section class="publishlayer-knowledge-base">
        @includeWhen(!empty($breadcrumbs), 'publishlayer::knowledge.partials.breadcrumbs', ['breadcrumbs' => $breadcrumbs])

        <header>
            <h1>{{ $labels['knowledge_base'] ?? 'Knowledge Base' }}</h1>
            <p>{{ $labels['knowledge_base_intro'] ?? 'Browse synced Argusly knowledge articles rendered locally by your Laravel site.' }}</p>
        </header>

        @if (config('publishlayer.features.search', true))
            <section aria-label="{{ $labels['search_label'] ?? 'Search articles' }}">
                <form method="GET" action="{{ route('publishlayer.knowledge.index') }}" role="search">
                    <label for="publishlayer-knowledge-search">{{ $labels['search_label'] ?? 'Search articles' }}</label>
                    <input
                        id="publishlayer-knowledge-search"
                        type="search"
                        name="q"
                        value="{{ $searchQuery }}"
                        placeholder="{{ $labels['search_placeholder'] ?? 'Search the knowledge base' }}"
                    >
                    <button type="submit">{{ $labels['search_button'] ?? 'Search' }}</button>
                    @if ($searchQuery !== '')
                        <a href="{{ route('publishlayer.knowledge.index') }}">{{ $labels['search_clear'] ?? 'Clear search' }}</a>
                    @endif
                </form>
            </section>
        @endif

        @if (config('publishlayer.features.category_overview', true) && $categories->isNotEmpty())
            <section aria-label="{{ $labels['category_overview'] ?? 'Browse by category' }}">
                <h2>{{ $labels['category_overview'] ?? 'Browse by category' }}</h2>
                <div>
                    @foreach ($categories as $category)
                        @if (($category->published_articles_count ?? 0) > 0)
                            <article>
                                <h3>
                                    <a href="{{ route('publishlayer.knowledge.category', ['slug' => $category->slug]) }}">{{ $category->name }}</a>
                                </h3>
                                @if ($category->description)
                                    <p>{{ $category->description }}</p>
                                @endif
                                <p>{{ ($category->published_articles_count ?? 0) }} {{ $labels['articles'] ?? 'Articles' }}</p>
                            </article>
                        @endif
                    @endforeach
                </div>
            </section>
        @endif

        <section aria-label="{{ $labels['articles'] ?? 'Articles' }}">
            <header>
                <h2>{{ $labels['articles'] ?? 'Articles' }}</h2>
                <p>{{ $articles->total() }} {{ \Illuminate\Support\Str::plural(strtolower($labels['articles'] ?? 'Articles'), $articles->total()) }}</p>
            </header>

            @forelse ($articles as $article)
                <article>
                    <header>
                        <h3>
                            <a href="{{ route('publishlayer.knowledge.show', ['slug' => $article->slug]) }}">{{ $article->title }}</a>
                        </h3>
                        @if ($article->category)
                            <p>
                                {{ $labels['category'] ?? 'Category' }}:
                                <a href="{{ route('publishlayer.knowledge.category', ['slug' => $article->category->slug]) }}">{{ $article->category->name }}</a>
                            </p>
                        @endif
                        @if ($article->published_at)
                            <p>{{ $labels['published'] ?? 'Published' }} {{ $article->published_at->toFormattedDateString() }}</p>
                        @endif
                    </header>

                    @if ($article->summary)
                        <p>{{ $article->summary }}</p>
                    @endif
                </article>
            @empty
                <p>{{ $labels['no_articles'] ?? 'No published knowledge articles are available yet.' }}</p>
            @endforelse
        </section>

        @include('publishlayer::knowledge.partials.pagination', ['paginator' => $articles, 'labels' => $labels])
    </section>
@endsection
