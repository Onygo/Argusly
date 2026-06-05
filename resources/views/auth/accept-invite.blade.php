@extends('layouts.auth', ['title' => 'Accept invite'])

@section('content')
    <div class="content-card space-y-4">
        <h1 class="text-xl font-semibold text-textPrimary">Accept invite</h1>
        <p class="text-sm text-textSecondary">You are invited to join {{ $invite->organization?->name ?? 'organization' }}.</p>

        <form method="POST" action="{{ route('invite.store', $token) }}" class="space-y-3">
            @csrf
            <div>
                <label class="text-sm text-textSecondary" for="name">Full name</label>
                <input id="name" name="name" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" required>
            </div>
            <div>
                <label class="text-sm text-textSecondary" for="password">Password</label>
                <input id="password" name="password" type="password" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" required>
            </div>
            <div>
                <label class="text-sm text-textSecondary" for="password_confirmation">Confirm password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" required>
            </div>
            <button class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">Accept invite</button>
        </form>
    </div>
@endsection
