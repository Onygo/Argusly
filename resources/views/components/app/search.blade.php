<form role="search" method="GET" action="{{ route('app.search') }}" class="relative hidden min-w-0 flex-1 md:block">
    <label for="global-search" class="sr-only">Search Argusly</label>
    <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted">
        <x-app.icon name="search" class="size-4" />
    </span>
    <input id="global-search" name="q" value="{{ request('q') }}" type="search" placeholder="Search content, campaigns, contacts, topics..." class="h-10 w-full rounded-md border border-line bg-panel pl-9 pr-3 text-sm text-ink outline-none transition placeholder:text-muted focus:border-blue focus:bg-white focus:ring-2 focus:ring-blue/10" />
</form>
