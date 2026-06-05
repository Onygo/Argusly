@php
    $exceptionStatus = isset($exception) && method_exists($exception, 'getStatusCode')
        ? (string) $exception->getStatusCode()
        : null;

    $code = trim($__env->yieldContent('code')) ?: ($exceptionStatus ?: '500');
    $title = trim($__env->yieldContent('title')) ?: match ($code) {
        '401' => 'Toegang zonder sleutel',
        '403' => 'Deze deur zit op slot',
        '404' => 'Deze laag is verdwaald',
        '419' => 'Je sessie is gaan wandelen',
        '429' => 'Even ademhalen',
        '500' => 'De machine morst koffie',
        '503' => 'We poetsen de motorruimte',
        default => 'Er ging iets scheef',
    };
    $message = trim($__env->yieldContent('message')) ?: match ($code) {
        '401' => 'Je probeerde binnen te komen zonder badge. Heel menselijk, maar Argusly vraagt toch even om in te loggen.',
        '403' => 'Je bent op de juiste verdieping, maar deze kamer staat niet op je sleutelbos.',
        '404' => 'We hebben overal gekeken: briefs, drafts, publicaties, zelfs tussen de audit logs. Deze pagina is er niet.',
        '419' => 'De pagina stond te lang open en is uit beleefdheid zelf naar huis gegaan. Ververs of log opnieuw in.',
        '429' => 'Je bent sneller dan onze wachtrij comfortabel vindt. Geef het een paar tellen en probeer het opnieuw.',
        '500' => 'Onze contentmotor deed een dramatische cough, zette een semicolon verkeerd en kijkt nu heel schuldbewust.',
        '503' => 'We zijn kort aan het sleutelen. De boel komt zo weer terug met minder rook en meer vertrouwen.',
        default => 'Argusly vond een onverwachte afslag. We hebben de kaart rechtgelegd en je kunt zo weer verder.',
    };

    $homeUrl = url('/');
    $loginUrl = \Illuminate\Support\Facades\Route::has('login') ? route('login') : $homeUrl;
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $code }} - {{ $title }} | {{ \App\Support\Brand::product() }}</title>
    <meta name="robots" content="noindex, nofollow">
    @include('partials.brand-meta')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="min-h-full bg-background text-textPrimary antialiased">
    <main class="flex min-h-screen items-center">
        <section class="w-full border-y border-border bg-[#f6f5f2]">
            <div class="mx-auto grid min-h-screen max-w-6xl items-center gap-10 px-4 py-12 sm:px-6 lg:grid-cols-[minmax(0,0.95fr)_minmax(320px,1.05fr)] lg:py-16">
                <div class="max-w-2xl">
                    <a href="{{ $homeUrl }}" class="inline-flex items-center gap-2 rounded-md px-1 py-1 hover:bg-white/70">
                        <span class="inline-flex h-8 w-8 items-center justify-center rounded-md bg-accentYellow-100 text-accentYellow-900">
                            <i data-lucide="layers" class="h-4 w-4"></i>
                        </span>
                        <span class="text-sm font-semibold text-textPrimary">{{ \App\Support\Brand::product() }}</span>
                    </a>

                    <div class="mt-10 inline-flex items-center gap-2 rounded-full border border-publicPrimary/15 bg-white px-3 py-1 text-xs font-medium text-publicPrimary">
                        <i data-lucide="sparkles" class="h-3.5 w-3.5"></i>
                        <span>Foutcode {{ $code }}</span>
                    </div>

                    <h1 class="mt-5 text-balance text-4xl font-semibold tracking-tight text-textPrimary sm:text-5xl">
                        {{ $title }}
                    </h1>
                    <p class="mt-4 max-w-xl text-pretty text-base leading-7 text-textSecondary">
                        {{ $message }}
                    </p>

                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <a href="{{ $homeUrl }}" class="inline-flex items-center justify-center gap-2 rounded-xl bg-publicPrimary px-5 py-3 text-sm font-semibold text-white transition-colors hover:bg-publicPrimaryHover">
                            <i data-lucide="home" class="h-4 w-4"></i>
                            Terug naar start
                        </a>
                        <a href="{{ $loginUrl }}" class="inline-flex items-center justify-center gap-2 rounded-xl border border-border bg-white px-5 py-3 text-sm font-semibold text-textPrimary transition-colors hover:bg-surfaceMuted">
                            <i data-lucide="log-in" class="h-4 w-4"></i>
                            Naar de app
                        </a>
                    </div>
                </div>

                <div class="relative">
                    <div class="rounded-2xl border border-border bg-white p-4 shadow-sm">
                        <div class="flex items-center justify-between border-b border-divider pb-3">
                            <div class="flex items-center gap-2">
                                <span class="h-2.5 w-2.5 rounded-full bg-danger/70"></span>
                                <span class="h-2.5 w-2.5 rounded-full bg-warning/70"></span>
                                <span class="h-2.5 w-2.5 rounded-full bg-success/70"></span>
                            </div>
                            <span class="text-xs font-medium text-textMuted">incident-preview.pl</span>
                        </div>

                        <div class="grid gap-3 pt-4">
                            <div class="rounded-xl border border-border bg-[#f6f5f2] p-4">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <p class="text-xs font-semibold uppercase text-textFaint">Workflow</p>
                                        <p class="mt-1 text-sm font-semibold text-textPrimary">Zoek pagina en doe normaal</p>
                                    </div>
                                    <span class="rounded-full border border-publicPrimary/15 bg-white px-2.5 py-1 text-xs font-medium text-publicPrimary">Pauze</span>
                                </div>
                                <div class="mt-4 space-y-2">
                                    <div class="h-2 rounded-full bg-white"><span class="block h-2 w-4/5 rounded-full bg-publicPrimary"></span></div>
                                    <div class="h-2 rounded-full bg-white"><span class="block h-2 w-2/5 rounded-full bg-accentYellow-100"></span></div>
                                </div>
                            </div>

                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="rounded-xl border border-border bg-surface p-4">
                                    <i data-lucide="file-question" class="h-5 w-5 text-publicPrimary"></i>
                                    <p class="mt-3 text-sm font-semibold text-textPrimary">Status</p>
                                    <p class="mt-1 text-xs leading-5 text-textSecondary">Een pagina speelt verstoppertje met de router.</p>
                                </div>
                                <div class="rounded-xl border border-border bg-surface p-4">
                                    <i data-lucide="wrench" class="h-5 w-5 text-publicPrimary"></i>
                                    <p class="mt-3 text-sm font-semibold text-textPrimary">Actie</p>
                                    <p class="mt-1 text-xs leading-5 text-textSecondary">Rustig blijven. Geen dashboards gooien.</p>
                                </div>
                            </div>

                            <div class="rounded-xl border border-border bg-publicPrimary p-4 text-white">
                                <p class="font-mono text-xs leading-6 text-white/80">
                                    publishlayer:error {{ $code }}<br>
                                    retry_policy: eerst koffie, dan refresh<br>
                                    confidence: komt goed
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (window.lucide) {
                lucide.createIcons();
            }
        });
    </script>
</body>
</html>
