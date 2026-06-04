<footer class="border-t border-line bg-white">
    <div class="container-page py-14">
        <div class="grid gap-10 md:grid-cols-[1.4fr_repeat(4,1fr)]">
            <div>
                <x-brand />
                <p class="mt-4 max-w-xs text-sm leading-6 text-muted">AI Visibility and Agentic Marketing Intelligence. Built for the new search surfaces.</p>
            </div>
            @foreach ([
                'Platform' => [
                    ['Platform', route('marketing.page', 'platform')],
                    ['Security', route('marketing.page', 'security')],
                ],
                'Company' => [
                    ['About', route('marketing.page', 'about')],
                    ['Contact', route('marketing.contact')],
                ],
                'Legal' => [
                    ['Privacy Policy', route('marketing.page', 'privacy')],
                    ['Terms & Conditions', route('marketing.page', 'terms')],
                ],
                'Resources' => [
                    ['Sign in', route('login')],
                ],
            ] as $heading => $links)
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-[0.16em] text-muted">{{ $heading }}</h3>
                    <ul class="mt-4 space-y-3 text-sm text-muted">
                        @foreach ($links as [$link, $href])
                            <li><a href="{{ $href }}" class="hover:text-ink">{{ $link }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
        <div class="mt-12 flex flex-col gap-4 border-t border-line pt-8 text-xs text-muted sm:flex-row sm:items-center sm:justify-between">
            <p>&copy; {{ date('Y') }} Argusly.</p>
            <div class="flex gap-5"><a href="{{ route('marketing.page', 'privacy') }}">Privacy</a><a href="{{ route('marketing.page', 'terms') }}">Terms</a><a href="{{ route('marketing.contact') }}">Contact</a></div>
        </div>
    </div>
</footer>
