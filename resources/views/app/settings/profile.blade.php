<x-app.settings.layout title="Profile" description="Personal account access for the signed-in user.">
    @php
        $initials = collect(explode(' ', $user->name))
            ->map(fn ($part) => str($part)->substr(0, 1))
            ->take(2)
            ->implode('');
    @endphp

    <div class="grid gap-6 xl:grid-cols-[0.9fr_1.1fr]">
        <x-dashboard.section title="Account details" description="Your signed-in Argusly identity.">
            <div class="flex items-start gap-4">
                <div class="grid size-12 shrink-0 place-items-center rounded-full bg-ink text-sm font-bold text-white">
                    {{ $initials ?: 'A' }}
                </div>
                <div class="min-w-0">
                    <p class="truncate text-base font-semibold text-ink">{{ $user->name }}</p>
                    <p class="mt-1 truncate text-sm text-muted">{{ $user->email }}</p>
                </div>
            </div>

            <div class="mt-6 grid gap-3 sm:grid-cols-2">
                <x-settings.field label="Name" :value="$user->name" class="bg-white" />
                <x-settings.field label="Email" :value="$user->email" class="bg-white" />
            </div>
        </x-dashboard.section>

        <x-dashboard.section title="Password" description="Update the password used for direct account access.">
            @if (session('status'))
                <div class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm font-semibold text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            <form method="POST" action="{{ route('settings.profile.password.update') }}" class="grid gap-4">
                @csrf
                @method('PATCH')

                <label class="block">
                    <span class="text-sm font-semibold text-ink">Current password</span>
                    <input name="current_password" type="password" autocomplete="current-password" required class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    @error('current_password')
                        <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span>
                    @enderror
                </label>

                <label class="block">
                    <span class="text-sm font-semibold text-ink">New password</span>
                    <input name="password" type="password" autocomplete="new-password" required class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    @error('password')
                        <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span>
                    @enderror
                </label>

                <label class="block">
                    <span class="text-sm font-semibold text-ink">Confirm new password</span>
                    <input name="password_confirmation" type="password" autocomplete="new-password" required class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                </label>

                <div>
                    <x-ui.button type="submit" variant="dark">Update password</x-ui.button>
                </div>
            </form>
        </x-dashboard.section>
    </div>
</x-app.settings.layout>
