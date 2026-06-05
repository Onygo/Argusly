@extends('layouts.auth', ['title' => 'Verify your email'])

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
        <p class="text-sm text-textSecondary">Enter the one-time code sent to {{ $email }}</p>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-border bg-surface p-3 text-sm text-textPrimary">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="rounded-md border border-danger/40 bg-danger/10 p-3 text-sm text-danger">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form class="space-y-4" method="POST" action="{{ route('verify-code.store') }}">
        @csrf
        <div class="space-y-2">
            <label class="text-sm font-medium leading-none" for="code">Verification code</label>
            <input
                type="text"
                class="flex h-10 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm placeholder:text-textSecondary focus:outline-none focus:ring-2 focus:ring-primarySoftRing"
                id="code"
                name="code"
                inputmode="numeric"
                maxlength="6"
                autocomplete="one-time-code"
                placeholder="123456"
                required
                value="{{ old('code') }}"
            >
            <p class="text-xs text-textSecondary">Code expires in {{ $expiresInMinutes }} minutes.</p>
        </div>
        <button class="inline-flex h-10 w-full items-center justify-center gap-2 whitespace-nowrap rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse transition-colors hover:bg-primaryHover focus:outline-none focus:ring-2 focus:ring-primarySoftRing" type="submit">Verify code</button>
    </form>

    <form class="space-y-2" method="POST" action="{{ route('verify-code.resend') }}">
        @csrf
        <button class="inline-flex h-10 w-full items-center justify-center gap-2 whitespace-nowrap rounded-md border border-border bg-surface px-4 py-2 text-sm font-medium text-textPrimary transition-colors hover:bg-surfaceMuted focus:outline-none focus:ring-2 focus:ring-primarySoftRing" type="submit">Resend code</button>
        <p class="text-center text-xs text-textSecondary">You can request a new code every {{ $resendCooldownSeconds }} seconds.</p>
    </form>
@endsection
