@extends('layouts.app', ['title' => 'Edit Automation'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Edit automation</x-slot:title>
        <x-slot:description>{{ $automation->name }}</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
        <a href="{{ route('app.content.automations.show', $automation) }}" class="rounded border border-border px-4 py-2 text-sm">Back to detail</a>
@endsection

@section('content')

    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-rose-300/60 bg-rose-500/5 px-4 py-3 text-sm text-rose-800">
            Please fix the highlighted fields and try again.
        </div>
    @endif

    <form method="POST" action="{{ route('app.content.automations.update', $automation) }}">
        @csrf
        @method('PUT')
        @include('app.content.automations._form')
    </form>
@endsection
