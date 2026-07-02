@extends('layouts.app', ['title' => 'Image Presets'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Image Presets</x-slot:title>
        <x-slot:description>Define visual styles for AI-generated images. The default preset is used automatically during image generation.</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    <div class="space-y-6">
        <header class="space-y-3">
            <nav class="text-sm text-textSecondary">
                <a href="{{ route('app.settings') }}" class="hover:text-textPrimary">Settings</a>
                <span class="mx-1">/</span>
                <span class="text-textPrimary">Image Presets</span>
            </nav>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <a href="{{ route('app.settings.image-presets.create') }}" class="inline-flex items-center justify-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">
                    <i data-lucide="plus" class="h-4 w-4"></i>
                    Create preset
                </a>
            </div>
        </header>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        <x-settings.section-card
            title="Your Presets"
            description="Presets define styling instructions sent to the AI when generating images."
        >
            @if ($presets->isEmpty())
                <x-settings.empty-state
                    title="No presets yet"
                    description="Create your first image preset to define a consistent visual style for AI-generated images."
                />
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-textSecondary border-b border-border">
                                <th class="pb-3 font-medium">Name</th>
                                <th class="pb-3 font-medium">Instructions</th>
                                <th class="pb-3 font-medium">Status</th>
                                <th class="pb-3 font-medium text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($presets as $preset)
                                <tr>
                                    <td class="py-3 pr-4">
                                        <span class="font-medium text-textPrimary">{{ $preset->name }}</span>
                                    </td>
                                    <td class="py-3 pr-4">
                                        <span class="text-textSecondary text-xs">{{ $preset->getInstructionsPreview(80) }}</span>
                                    </td>
                                    <td class="py-3 pr-4">
                                        @if ($preset->is_default)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs font-medium text-emerald-700">
                                                <i data-lucide="check" class="h-3 w-3"></i>
                                                Default
                                            </span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-surfaceSubtle px-2.5 py-1 text-xs text-textSecondary">
                                                Custom
                                            </span>
                                        @endif
                                    </td>
                                    <td class="py-3">
                                        <div class="flex items-center justify-end gap-2">
                                            @if (! $preset->is_default)
                                                <form method="POST" action="{{ route('app.settings.image-presets.set-default', $preset) }}">
                                                    @csrf
                                                    <button type="submit" class="inline-flex items-center justify-center rounded-md border border-border px-3 py-1.5 text-xs font-medium text-textPrimary hover:bg-surfaceSubtle">
                                                        Set default
                                                    </button>
                                                </form>
                                            @endif
                                            <a href="{{ route('app.settings.image-presets.edit', $preset) }}" class="inline-flex items-center justify-center rounded-md border border-border px-3 py-1.5 text-xs font-medium text-textPrimary hover:bg-surfaceSubtle">
                                                Edit
                                            </a>
                                            <form method="POST" action="{{ route('app.settings.image-presets.destroy', $preset) }}" onsubmit="return confirm('Delete this preset? This action cannot be undone.')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="inline-flex items-center justify-center rounded-md border border-border px-3 py-1.5 text-xs font-medium text-danger hover:bg-danger/5">
                                                    Delete
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-settings.section-card>

        <x-settings.section-card
            title="How Presets Work"
            description="Understanding image preset behavior in content generation."
        >
            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-lg border border-border bg-background p-4">
                    <div class="flex items-center gap-2 text-textPrimary">
                        <i data-lucide="star" class="h-4 w-4 text-accentYellow-900"></i>
                        <h3 class="text-sm font-semibold">Default Preset</h3>
                    </div>
                    <p class="mt-2 text-xs text-textSecondary">The default preset is automatically applied when generating images, unless you explicitly select a different one.</p>
                </div>
                <div class="rounded-lg border border-border bg-background p-4">
                    <div class="flex items-center gap-2 text-textPrimary">
                        <i data-lucide="image" class="h-4 w-4 text-primary"></i>
                        <h3 class="text-sm font-semibold">Style Instructions</h3>
                    </div>
                    <p class="mt-2 text-xs text-textSecondary">Instructions guide the AI on visual style: colors, lighting, composition, mood, and artistic direction.</p>
                </div>
                <div class="rounded-lg border border-border bg-background p-4">
                    <div class="flex items-center gap-2 text-textPrimary">
                        <i data-lucide="palette" class="h-4 w-4 text-indigo-600"></i>
                        <h3 class="text-sm font-semibold">Brand Consistency</h3>
                    </div>
                    <p class="mt-2 text-xs text-textSecondary">Create presets matching your brand guidelines to ensure all generated images maintain a consistent visual identity.</p>
                </div>
            </div>
        </x-settings.section-card>
    </div>
@endsection
