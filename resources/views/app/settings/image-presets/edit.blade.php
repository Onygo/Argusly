@extends('layouts.app', ['title' => 'Edit Image Preset', 'pageWidth' => 'constrained'])

@section('content')
    <div class="space-y-6">
        <header class="space-y-3">
            <nav class="text-sm text-textSecondary">
                <a href="{{ route('app.settings') }}" class="hover:text-textPrimary">Settings</a>
                <span class="mx-1">/</span>
                <a href="{{ route('app.settings.image-presets.index') }}" class="hover:text-textPrimary">Image Presets</a>
                <span class="mx-1">/</span>
                <span class="text-textPrimary">Edit</span>
            </nav>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Edit Image Preset</h1>
                    <p class="text-textSecondary mt-1">Update the visual style instructions for "{{ $preset->name }}".</p>
                </div>
                @if ($preset->is_default)
                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-3 py-1 text-xs font-medium text-emerald-700">
                        <i data-lucide="check" class="h-3 w-3"></i>
                        Default Preset
                    </span>
                @endif
            </div>
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
            description="Update the preset name and styling instructions."
        >
            <form method="POST" action="{{ route('app.settings.image-presets.update', $preset) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div>
                    <label for="name" class="mb-1 block text-sm font-medium text-textPrimary">Preset Name</label>
                    <input
                        type="text"
                        id="name"
                        name="name"
                        value="{{ old('name', $preset->name) }}"
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
                        rows="10"
                        class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm font-mono focus:border-primary focus:ring-1 focus:ring-primary"
                        placeholder="Describe the visual style you want for generated images."
                        maxlength="5000"
                        required
                    >{{ old('instructions', $preset->instructions) }}</textarea>
                    <p class="mt-1 text-xs text-textSecondary">These instructions are sent to the AI model when generating images. Be specific about colors, mood, lighting, and composition.</p>
                    @error('instructions')
                        <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                    @enderror
                </div>

                @if (! $preset->is_default)
                    <div>
                        <label class="flex items-start gap-3 rounded-md border border-border bg-background px-3 py-3">
                            <input
                                type="checkbox"
                                name="is_default"
                                value="1"
                                class="mt-0.5"
                                {{ old('is_default', $preset->is_default) ? 'checked' : '' }}
                            >
                            <span>
                                <span class="block text-sm font-medium text-textPrimary">Set as default preset</span>
                                <span class="block text-xs text-textSecondary">This preset will be automatically used for all image generation unless another is explicitly selected.</span>
                            </span>
                        </label>
                    </div>
                @endif

                <x-settings.form-actions>
                    <a href="{{ route('app.settings.image-presets.index') }}" class="inline-flex items-center justify-center rounded-md border border-border px-4 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                        Cancel
                    </a>
                    <button type="submit" class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">
                        Save changes
                    </button>
                </x-settings.form-actions>
            </form>
        </x-settings.section-card>

        <x-settings.section-card
            title="Danger Zone"
            description="Irreversible actions for this preset."
        >
            <div class="flex flex-wrap items-center justify-between gap-4 rounded-lg border border-danger/30 bg-danger/5 p-4">
                <div>
                    <h3 class="text-sm font-semibold text-danger">Delete this preset</h3>
                    <p class="mt-1 text-xs text-textSecondary">Once deleted, this preset cannot be recovered. Images already generated will not be affected.</p>
                </div>
                <form method="POST" action="{{ route('app.settings.image-presets.destroy', $preset) }}" onsubmit="return confirm('Are you sure you want to delete this preset? This action cannot be undone.')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="inline-flex items-center justify-center rounded-md border border-danger bg-white px-4 py-2 text-sm font-medium text-danger hover:bg-danger/5">
                        Delete preset
                    </button>
                </form>
            </div>
        </x-settings.section-card>
    </div>
@endsection
