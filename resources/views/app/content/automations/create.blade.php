@extends('layouts.app', ['title' => 'Create Automation'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>New content automation</x-slot:title>
        <x-slot:description>Create an automation that plans and generates single posts, chains, or pillar clusters on a schedule.</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
        <a href="{{ route('app.content.automations.index', ['workspace' => $selectedWorkspaceId]) }}" class="rounded border border-border px-4 py-2 text-sm">Back to automations</a>
@endsection

@section('content')

    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-rose-300/60 bg-rose-500/5 px-4 py-3 text-sm text-rose-800">
            Please fix the highlighted fields and try again.
        </div>
    @endif

    <form method="POST" action="{{ route('app.content.automations.store') }}">
        @csrf
        @include('app.content.automations._form')
    </form>
@endsection
