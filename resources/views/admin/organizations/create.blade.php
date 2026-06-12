@extends('layouts.admin', ['title' => 'Create Organization', 'pageWidth' => 'constrained'])

@section('content')
    <div class="mb-6 flex items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Create organization</h1>
            <p class="mt-1 text-textSecondary">Create a customer account with an owner, workspace, and publishing site.</p>
        </div>
        <a href="{{ route('admin.organizations') }}" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Back to organizations</a>
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded border border-danger/30 bg-danger/5 px-3 py-2 text-sm text-danger">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('admin.organizations.store') }}" class="space-y-4">
        @csrf

        <section class="rounded-lg border border-border bg-surface p-4">
            <h2 class="text-sm font-semibold text-textPrimary">Organization</h2>
            <div class="mt-4 grid gap-3 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Name</label>
                    <input type="text" name="name" required maxlength="190" value="{{ old('name', 'Argusly') }}" class="pl-input w-full" />
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Slug</label>
                    <input type="text" name="slug" required maxlength="190" value="{{ old('slug', 'argusly') }}" class="pl-input w-full" />
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Status</label>
                    <select name="status" required class="pl-input w-full">
                        @foreach (['active' => 'Active', 'pending' => 'Pending', 'on_hold' => 'On hold'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('status', 'active') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Access tier</label>
                    <select name="access_tier" required class="pl-input w-full">
                        @foreach (['paid' => 'Paid', 'early_bird' => 'Early Bird', 'trial' => 'Trial', 'free' => 'Free'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('access_tier', 'paid') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs text-textSecondary">Billing email</label>
                    <input type="email" name="billing_email" maxlength="190" value="{{ old('billing_email') }}" class="pl-input w-full" />
                </div>
            </div>
        </section>

        <section class="rounded-lg border border-border bg-surface p-4">
            <h2 class="text-sm font-semibold text-textPrimary">Owner user</h2>
            <div class="mt-4 grid gap-3 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Name</label>
                    <input type="text" name="owner_name" required maxlength="190" value="{{ old('owner_name') }}" class="pl-input w-full" />
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Email</label>
                    <input type="email" name="owner_email" required maxlength="190" value="{{ old('owner_email') }}" class="pl-input w-full" />
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Password</label>
                    <input type="password" name="owner_password" required autocomplete="new-password" class="pl-input w-full" />
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Confirm password</label>
                    <input type="password" name="owner_password_confirmation" required autocomplete="new-password" class="pl-input w-full" />
                </div>
                <label class="flex items-center gap-2 md:col-span-2">
                    <input type="checkbox" name="owner_active" value="1" @checked(old('owner_active', '1')) class="h-4 w-4 rounded border-border">
                    <span class="text-sm text-textPrimary">Activate and approve owner immediately</span>
                </label>
            </div>
        </section>

        <section class="rounded-lg border border-border bg-surface p-4">
            <label class="flex items-center gap-2">
                <input type="checkbox" name="create_workspace" value="1" @checked(old('create_workspace', '1')) class="h-4 w-4 rounded border-border">
                <span class="text-sm font-semibold text-textPrimary">Create workspace</span>
            </label>
            <div class="mt-4 grid gap-3 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Workspace name</label>
                    <input type="text" name="workspace_name" maxlength="190" value="{{ old('workspace_name', 'Argusly') }}" class="pl-input w-full" />
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Default content language</label>
                    <select name="default_content_language" class="pl-input w-full">
                        <option value="en" @selected(old('default_content_language', 'en') === 'en')>English</option>
                        <option value="nl" @selected(old('default_content_language', 'en') === 'nl')>Dutch</option>
                    </select>
                </div>
            </div>
        </section>

        <section class="rounded-lg border border-border bg-surface p-4">
            <label class="flex items-center gap-2">
                <input type="checkbox" name="create_site" value="1" @checked(old('create_site', '1')) class="h-4 w-4 rounded border-border">
                <span class="text-sm font-semibold text-textPrimary">Create publishing site</span>
            </label>
            <div class="mt-4 grid gap-3 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Site name</label>
                    <input type="text" name="site_name" maxlength="190" value="{{ old('site_name', 'Argusly marketing site') }}" class="pl-input w-full" />
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Site type</label>
                    <select name="site_type" class="pl-input w-full">
                        <option value="laravel" @selected(old('site_type', 'laravel') === 'laravel')>Laravel</option>
                        <option value="wordpress" @selected(old('site_type', 'laravel') === 'wordpress')>WordPress</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs text-textSecondary">Site URL</label>
                    <input type="url" name="site_url" maxlength="255" value="{{ old('site_url', config('app.url')) }}" class="pl-input w-full" />
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs text-textSecondary">Allowed domains</label>
                    <textarea name="allowed_domains" rows="3" class="pl-textarea w-full" placeholder="argusly.com&#10;www.argusly.com">{{ old('allowed_domains') }}</textarea>
                </div>
            </div>
        </section>

        <div class="flex justify-end gap-2">
            <a href="{{ route('admin.organizations') }}" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Cancel</a>
            <button type="submit" class="rounded border border-border bg-textPrimary px-3 py-2 text-sm font-medium text-white hover:opacity-90">Create organization</button>
        </div>
    </form>
@endsection
