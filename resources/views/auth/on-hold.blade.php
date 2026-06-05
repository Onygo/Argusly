@extends('layouts.auth', ['title' => 'Account on hold'])

@section('content')
    <div class="rounded-md border border-border bg-surface p-5 space-y-2">
        <h1 class="text-xl font-semibold tracking-tight text-textPrimary">Your organization is on hold</h1>
        <p class="text-sm text-textSecondary">Please contact support to restore access.</p>
        <a class="inline-flex h-10 items-center justify-center gap-2 whitespace-nowrap rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse transition-colors hover:bg-primaryHover focus:outline-none focus:ring-2 focus:ring-primarySoftRing" href="/">Back to home</a>
    </div>
@endsection
