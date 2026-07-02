@extends('layouts.app', ['title' => 'Billing & Credits'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Billing & Credits</x-slot:title>
        <x-slot:description>{{ $organization->name ?? 'Organization' }}</x-slot:description>
    </x-page-header>
@endsection

@section('content')

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    @if ($errors->has('billing'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first('billing') }}</div>
    @endif

    @include('app.billing.partials.overview')
    @include('app.billing.partials.actions')
    @include('app.billing.partials.wallets')
    @include('app.billing.partials.tabs')
    @include('app.billing.partials.drawer')
@endsection
