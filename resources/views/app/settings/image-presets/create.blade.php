@extends('layouts.app', ['title' => 'Create Image Preset', 'pageWidth' => 'constrained'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Create Image Preset</x-slot:title>
        <x-slot:description>Define a new visual style for AI-generated images.</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    <div class="space-y-6">
        <header class="space-y-3">
            <nav class="text-sm text-textSecondary">
                <a href="{{ route('app.settings') }}" class="hover:text-textPrimary">Settings</a>
                <span class="mx-1">/</span>
                <a href="{{ route('app.settings.image-presets.index') }}" class="hover:text-textPrimary">Image Presets</a>
                <span class="mx-1">/</span>
                <span class="text-textPrimary">Create</span>
            </nav>
        </header>

        @if ($errors->any())
            <div class="rounded-lg border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-danger">
                <p class="font-medium">Please fix the following errors:</p>
                <ul class="mt-1 list-disc pl-5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <x-settings.section-card
            title="Preset Details"
            description="Give your preset a name and define the styling instructions."
        >
            <form method="POST" action="{{ route('app.settings.image-presets.store') }}" class="space-y-5">
                @csrf

                <div>
                    <label for="name" class="mb-1 block text-sm font-medium text-textPrimary">Preset Name</label>
                    <input
                        type="text"
                        id="name"
                        name="name"
                        value="{{ old('name') }}"
                        class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm focus:border-primary focus:ring-1 focus:ring-primary"
                        placeholder="e.g., Modern Minimalist, Vibrant Tech, Corporate Blue"
                        maxlength="255"
                        required
                    >
                    <p class="mt-1 text-xs text-textSecondary">A descriptive name to identify this preset.</p>
                    @error('name')
                        <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="instructions" class="mb-1 block text-sm font-medium text-textPrimary">Style Instructions</label>
                    <textarea
                        id="instructions"
                        name="instructions"
                        rows="8"
                        class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm font-mono focus:border-primary focus:ring-1 focus:ring-primary"
                        placeholder="Describe the visual style you want for generated images. Include details about:&#10;- Color palette&#10;- Lighting and mood&#10;- Composition style&#10;- Artistic direction&#10;- Any specific elements to include or avoid"
                        maxlength="5000"
                        required
                    >{{ old('instructions') }}</textarea>
                    <p class="mt-1 text-xs text-textSecondary">These instructions are sent to the AI model when generating images. Be specific about colors, mood, lighting, and composition.</p>
                    @error('instructions')
                        <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="flex items-start gap-3 rounded-md border border-border bg-background px-3 py-3">
                        <input
                            type="checkbox"
                            name="is_default"
                            value="1"
                            class="mt-0.5"
                            {{ old('is_default') ? 'checked' : '' }}
                        >
                        <span>
                            <span class="block text-sm font-medium text-textPrimary">Set as default preset</span>
                            <span class="block text-xs text-textSecondary">This preset will be automatically used for all image generation unless another is explicitly selected.</span>
                        </span>
                    </label>
                </div>

                <x-settings.form-actions>
                    <a href="{{ route('app.settings.image-presets.index') }}" class="inline-flex items-center justify-center rounded-md border border-border px-4 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                        Cancel
                    </a>
                    <button type="submit" class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">
                        Create preset
                    </button>
                </x-settings.form-actions>
            </form>
        </x-settings.section-card>

        <x-settings.section-card
            title="Example Instructions"
            description="Here are some example style instructions to help you get started."
        >
            <div class="grid gap-4 md:grid-cols-2">
                <div class="rounded-lg border border-border bg-background p-4">
                    <h3 class="text-sm font-semibold text-textPrimary">Modern Minimalist</h3>
                    <p class="mt-2 text-xs text-textSecondary font-mono whitespace-pre-line">Clean and minimal aesthetic
Soft, neutral color palette with subtle accent colors
Natural lighting with soft shadows
Plenty of negative space
Simple geometric shapes
Professional and contemporary feel
No text overlays or logos</p>
                </div>
                <div class="rounded-lg border border-border bg-background p-4">
                    <h3 class="text-sm font-semibold text-textPrimary">Vibrant Tech</h3>
                    <p class="mt-2 text-xs text-textSecondary font-mono whitespace-pre-line">Bold, vibrant color gradients
Neon accents on dark backgrounds
Dynamic lighting with glowing effects
Abstract technological elements
Futuristic and innovative mood
High contrast and visual energy
Suitable for tech blog headers</p>
                </div>
            </div>
        </x-settings.section-card>
    </div>
@endsection
