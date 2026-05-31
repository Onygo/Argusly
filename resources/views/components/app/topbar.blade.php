@php
    $account = current_account();
    $brand = current_brand();
    $user = auth()->user();
    $initials = $user ? collect(explode(' ', $user->name))->map(fn ($part) => str($part)->substr(0, 1))->take(2)->implode('') : 'A';
    $unreadNotifications = ($user && $account)
        ? app(\App\Services\NotificationService::class)->unreadCount($user, $account, $brand)
        : 0;
@endphp

<header class="border-b border-line bg-white">
    <div class="flex h-16 items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-muted">{{ __('common.account') }}</p>
            <div class="flex items-center gap-3">
                <span class="truncate text-sm font-semibold text-ink">{{ $account?->name ?? __('dashboard.no_account_selected') }}</span>
                <span class="hidden rounded-full border border-line px-2 py-0.5 text-xs text-muted sm:inline">{{ $brand?->name ?? __('dashboard.no_brand_selected') }}</span>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('app.notifications') }}" class="relative rounded-full border border-line bg-white px-3 py-2 text-xs font-semibold text-muted transition hover:border-slate-300 hover:text-ink">
                Notifications
                @if ($unreadNotifications > 0)
                    <span class="ml-1 rounded-full bg-blue px-1.5 py-0.5 text-[10px] font-bold text-white">{{ $unreadNotifications }}</span>
                @endif
            </a>
            <span class="hidden rounded-full border border-line bg-white px-3 py-2 text-xs font-semibold text-muted sm:inline">{{ $user?->email }}</span>
            <form method="POST" action="{{ route('user.locale.update') }}">
                @csrf
                <label class="sr-only" for="user-locale">{{ __('languages.switch_label') }}</label>
                <select id="user-locale" name="locale" onchange="this.form.submit()" class="h-9 rounded-full border border-line bg-white px-3 text-xs font-semibold text-muted">
                    <option value="en" @selected(app()->getLocale() === 'en')>{{ __('languages.english') }}</option>
                    <option value="nl" @selected(app()->getLocale() === 'nl')>{{ __('languages.dutch') }}</option>
                </select>
            </form>
            <div class="grid size-9 place-items-center rounded-full bg-ink text-xs font-bold text-white">{{ $initials }}</div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="rounded-full border border-line bg-white px-3 py-2 text-xs font-semibold text-muted transition hover:border-slate-300 hover:text-ink">
                    {{ __('common.logout') }}
                </button>
            </form>
        </div>
    </div>
</header>
