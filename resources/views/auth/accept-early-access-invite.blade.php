@extends('layouts.auth', ['title' => __('public.early_access.invite_title')])

@section('content')
    <div class="content-card space-y-4">
        <h1 class="text-xl font-semibold text-textPrimary">{{ __('public.early_access.invite_title') }}</h1>
        <p class="text-sm text-textSecondary">
            @if ($signup?->company_name)
                {{ __('public.early_access.invite_approved_with_company', ['company' => $signup->company_name]) }}
            @else
                {{ __('public.early_access.invite_approved') }}
            @endif
        </p>

        @if ($invite->expires_at)
            <p class="text-xs text-textSecondary">{{ __('public.early_access.invite_expires', ['date' => $invite->expires_at->format('Y-m-d H:i')]) }}</p>
        @endif

        <form method="POST" action="{{ route('public.early-access.invites.store', $token) }}" class="space-y-3">
            @csrf
            <div>
                <label class="text-sm text-textSecondary" for="name">{{ __('public.early_access.invite_full_name') }}</label>
                <input id="name" name="name" value="{{ old('name', $signup?->full_name) }}" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" required>
            </div>
            <div>
                <label class="text-sm text-textSecondary" for="password">{{ __('public.early_access.invite_password') }}</label>
                <input id="password" name="password" type="password" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" required>
            </div>
            <div>
                <label class="text-sm text-textSecondary" for="password_confirmation">{{ __('public.early_access.invite_confirm_password') }}</label>
                <input id="password_confirmation" name="password_confirmation" type="password" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" required>
            </div>
            @error('invite')
                <p class="text-xs text-rose-700">{{ $message }}</p>
            @enderror
            <button class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">{{ __('public.early_access.invite_activate') }}</button>
        </form>
    </div>
@endsection
