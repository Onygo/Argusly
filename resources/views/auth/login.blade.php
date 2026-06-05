@extends('layouts.auth', ['title' => __('public.auth.login_title')])

@section('content')
    <div class="flex flex-col items-center gap-3">
        <a href="{{ route('landing') }}" class="inline-flex items-center gap-2 rounded-md px-2 py-1 hover:bg-surfaceMuted">
            <span class="inline-flex h-9 w-9 items-center justify-center rounded-md bg-accentYellow-100 text-accentYellow-900">
                <i data-lucide="layers" class="h-5 w-5"></i>
            </span>
            <span class="leading-tight">
                <span class="block text-lg font-semibold text-textPrimary">{{ \App\Support\Brand::product() }}</span>
            </span>
        </a>
        <p class="text-sm text-textSecondary">{{ __('public.auth.login_subtitle') }}</p>
    </div>

    @if ($errors->any())
        <div class="rounded-md border border-danger/40 bg-danger/10 p-3 text-sm text-danger">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form class="space-y-4" method="POST" action="{{ route('login.store') }}">
        @csrf
        <div class="space-y-2">
            <label class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70" for="email">{{ __('public.auth.email') }}</label>
            <input type="email" class="flex h-10 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm placeholder:text-textSecondary focus:outline-none focus:ring-2 focus:ring-primarySoftRing" id="email" name="email" placeholder="you@company.com" required value="{{ old('email') }}">
        </div>
        <div class="space-y-2">
            <label class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70" for="password">{{ __('public.auth.password') }}</label>
            <input type="password" class="flex h-10 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm placeholder:text-textSecondary focus:outline-none focus:ring-2 focus:ring-primarySoftRing" id="password" name="password" placeholder="••••••••" required>
        </div>
        <button class="inline-flex h-10 w-full items-center justify-center gap-2 whitespace-nowrap rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse transition-colors hover:bg-primaryHover focus:outline-none focus:ring-2 focus:ring-primarySoftRing" type="submit">{{ __('public.auth.sign_in') }}</button>
    </form>

    @if ((bool) config('publishlayer.launch.public_registration_enabled', true))
        <p class="text-center text-sm text-textSecondary">
            {{ __('public.auth.no_account') }}
            <a class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium text-primary underline-offset-4 hover:underline h-auto p-0" href="{{ route('register', ['plan' => 'creator']) }}">{{ __('public.auth.request_account') }}</a>
        </p>
    @else
        <p class="text-center text-sm text-textSecondary">
            {{ __('public.auth.need_access') }}
            <a class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium text-primary underline-offset-4 hover:underline h-auto p-0" href="{{ route('public.early-access.show') }}">{{ __('public.auth.request_early_access') }}</a>
        </p>
    @endif

    <p class="text-center text-sm">
        <a class="text-textSecondary hover:text-textPrimary underline-offset-4 hover:underline" href="{{ route('landing') }}">{{ __('public.auth.back_to_site') }}</a>
    </p>
@endsection
