@php
    $account = current_account();
    $brand = current_brand();
    $user = auth()->user();
    $initials = $user ? collect(explode(' ', $user->name))->map(fn ($part) => str($part)->substr(0, 1))->take(2)->implode('') : 'A';
    $unreadNotifications = ($user && $account)
        ? app(\App\Services\NotificationService::class)->unreadCount($user, $account, $brand)
        : 0;
    $accounts = $user ? $user->accounts()->wherePivot('status', 'active')->orderBy('name')->get() : collect();
    $brands = $user && $account
        ? $user->brands()->wherePivot('status', 'active')->wherePivot('account_id', $account->id)->orderBy('name')->get()
        : collect();
@endphp

<header class="sticky top-0 z-30 border-b border-line bg-white/95 backdrop-blur">
    <div class="flex h-16 items-center gap-3 px-4 sm:px-6 lg:px-8">
        <button type="button" data-mobile-sidebar-open class="grid size-10 place-items-center rounded-md border border-line text-muted transition hover:bg-panel hover:text-ink lg:hidden" aria-label="Open navigation">
            <x-app.icon name="menu" class="size-5" />
        </button>

        <div class="hidden min-w-0 items-center gap-2 xl:flex">
            @if ($accounts->count() > 1)
                <form method="POST" action="{{ route('tenant.account.switch') }}">
                    @csrf
                    <label class="sr-only" for="account-switcher">Account</label>
                    <select id="account-switcher" name="account_id" onchange="this.form.submit()" class="h-10 max-w-48 rounded-md border border-line bg-white px-3 text-sm font-semibold text-ink">
                        @foreach ($accounts as $availableAccount)
                            <option value="{{ $availableAccount->id }}" @selected($account?->id === $availableAccount->id)>{{ $availableAccount->name }}</option>
                        @endforeach
                    </select>
                </form>
            @else
                <span class="max-w-48 truncate rounded-md border border-line bg-white px-3 py-2 text-sm font-semibold text-ink">{{ $account?->name ?? __('dashboard.no_account_selected') }}</span>
            @endif

            @if ($brands->count() > 1)
                <form method="POST" action="{{ route('tenant.brand.switch') }}">
                    @csrf
                    <label class="sr-only" for="brand-switcher">Brand</label>
                    <select id="brand-switcher" name="brand_id" onchange="this.form.submit()" class="h-10 max-w-48 rounded-md border border-line bg-white px-3 text-sm font-semibold text-ink">
                        @foreach ($brands as $availableBrand)
                            <option value="{{ $availableBrand->id }}" @selected($brand?->id === $availableBrand->id)>{{ $availableBrand->name }}</option>
                        @endforeach
                    </select>
                </form>
            @else
                <span class="max-w-48 truncate rounded-md border border-line bg-white px-3 py-2 text-sm font-semibold text-muted">{{ $brand?->name ?? __('dashboard.no_brand_selected') }}</span>
            @endif

        </div>

        <x-app.search />

        <div class="ml-auto flex items-center gap-2">
            <a href="{{ route('app.notifications') }}" class="relative grid size-10 place-items-center rounded-md border border-line bg-white text-muted transition hover:border-slate-300 hover:bg-panel hover:text-ink" aria-label="Notifications">
                <x-app.icon name="bell" class="size-4" />
                @if ($unreadNotifications > 0)
                    <span class="absolute -right-1 -top-1 rounded-full bg-blue px-1.5 py-0.5 text-[10px] font-bold leading-none text-white">{{ $unreadNotifications }}</span>
                @endif
            </a>

            <form method="POST" action="{{ route('user.locale.update') }}" class="hidden sm:block">
                @csrf
                <label class="sr-only" for="user-locale">{{ __('languages.switch_label') }}</label>
                <select id="user-locale" name="locale" onchange="this.form.submit()" class="h-10 rounded-md border border-line bg-white px-3 text-xs font-semibold text-muted">
                    <option value="en" @selected(app()->getLocale() === 'en')>{{ __('languages.english') }}</option>
                    <option value="nl" @selected(app()->getLocale() === 'nl')>{{ __('languages.dutch') }}</option>
                </select>
            </form>

            <details class="relative">
                <summary class="grid size-10 cursor-pointer list-none place-items-center rounded-full bg-ink text-xs font-bold text-white">{{ $initials }}</summary>
                <div class="absolute right-0 mt-2 w-64 rounded-md border border-line bg-white p-2 ">
                    <div class="border-b border-line px-3 py-2">
                        <p class="truncate text-sm font-semibold text-ink">{{ $user?->name }}</p>
                        <p class="truncate text-xs text-muted">{{ $user?->email }}</p>
                    </div>
                    <form method="POST" action="{{ route('logout') }}" class="mt-2">
                        @csrf
                        <button type="submit" class="w-full rounded-md px-3 py-2 text-left text-sm font-semibold text-muted transition hover:bg-panel hover:text-ink">
                            {{ __('common.logout') }}
                        </button>
                    </form>
                </div>
            </details>
        </div>
    </div>
</header>
