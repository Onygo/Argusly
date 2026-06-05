@extends('layouts.app', ['title' => 'Edit Automation'])

@section('content')
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Edit automation</h1>
            <p class="mt-1 text-sm text-textSecondary">{{ $automation->name }}</p>
        </div>
        <a href="{{ route('app.content.automations.show', $automation) }}" class="rounded border border-border px-4 py-2 text-sm">Back to detail</a>
    </div>

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
