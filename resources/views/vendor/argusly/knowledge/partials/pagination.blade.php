@if ($paginator->hasPages())
    <nav aria-label="Pagination">
        <p>{{ str_replace([':current', ':last'], [(string) $paginator->currentPage(), (string) $paginator->lastPage()], $labels['pagination_summary'] ?? 'Page :current of :last') }}</p>
        <div>
            @if ($paginator->onFirstPage())
                <span>{{ $labels['pagination_previous'] ?? 'Previous' }}</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}">{{ $labels['pagination_previous'] ?? 'Previous' }}</a>
            @endif

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}">{{ $labels['pagination_next'] ?? 'Next' }}</a>
            @else
                <span>{{ $labels['pagination_next'] ?? 'Next' }}</span>
            @endif
        </div>
    </nav>
@endif
