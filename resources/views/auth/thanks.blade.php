@extends('layouts.auth', ['title' => 'Aanvraag ontvangen'])

@section('content')
    <div class="rounded-md border border-border bg-surface p-5 space-y-2">
        <h1 class="text-xl font-semibold tracking-tight text-textPrimary">Thanks, your access request is pending approval.</h1>
        <p class="text-sm text-textSecondary">We will notify you when your account is approved.</p>
        <a class="inline-flex h-10 items-center justify-center gap-2 whitespace-nowrap rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse transition-colors hover:bg-primaryHover focus:outline-none focus:ring-2 focus:ring-primarySoftRing" href="{{ route('login') }}">Terug naar inloggen</a>
    </div>
@endsection
