@extends('layouts.app', ['title' => 'LinkedIn Integration'])

@section('content')
    <div class="mx-auto max-w-4xl space-y-6">
        <div>
            <p class="text-sm text-textSecondary">Settings / Integrations</p>
            <h1 class="text-xl font-semibold text-textPrimary">LinkedIn</h1>
        </div>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        @if ($errors->any())
            <div class="rounded-md border border-danger/20 bg-danger/5 px-4 py-3 text-sm text-danger">{{ $errors->first() }}</div>
        @endif

        <section class="rounded-lg border border-border bg-surface p-5">
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-md border border-border bg-background">
                        <x-app.icon name="linkedin" class="h-4 w-4 text-textSecondary" />
                    </span>
                    <div>
                        <h2 class="text-base font-semibold text-textPrimary">Connection</h2>
                        <p class="mt-1 text-sm text-textSecondary">
                            {{ $accounts->filter(fn ($item) => $item->isConnected())->count() }} connected LinkedIn identity/identities in this workspace.
                        </p>
                    </div>
                </div>
                <span class="rounded-full border border-border bg-background px-3 py-1 text-xs font-medium text-textSecondary">
                    {{ $accounts->count() }} account(s)
                </span>
            </div>

            <div class="mt-5 flex flex-wrap gap-3">
                @if ($linkedinEnabled && $canManageLinkedIn)
                    <a href="{{ route('app.settings.integrations.linkedin.connect', ['workspace_id' => $workspace->id]) }}" class="pl-btn-primary">
                        <i data-lucide="link" class="h-4 w-4"></i>
                        <span>Connect LinkedIn</span>
                    </a>
                @else
                    <button class="pl-btn-secondary opacity-60" disabled>
                        <i data-lucide="link" class="h-4 w-4"></i>
                        <span>Connect LinkedIn</span>
                    </button>
                @endif
            </div>

            <div class="mt-5 divide-y divide-border rounded-md border border-border bg-background">
                @forelse ($accounts as $linkedInAccount)
                    <div class="flex flex-col gap-3 p-3 md:flex-row md:items-center md:justify-between">
                        <div>
                            <p class="text-sm font-medium text-textPrimary">{{ $linkedInAccount->display_name }}</p>
                            <p class="mt-1 text-xs text-textSecondary">
                                {{ ucfirst((string) $linkedInAccount->account_type) }}
                                · {{ $linkedInAccount->ownerLabel() }}
                                · {{ $linkedInAccount->provider_member_urn ?: $linkedInAccount->platform_account_id ?: 'OAuth placeholder' }}
                            </p>
                            <p class="mt-1 text-xs text-textSecondary">Token {{ $linkedInAccount->expires_at?->isPast() ? 'expired' : 'active' }}{{ $linkedInAccount->expires_at ? ' until '.$linkedInAccount->expires_at->toDayDateTimeString() : '' }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="rounded-full border border-border bg-surface px-3 py-1 text-xs font-medium text-textSecondary">
                                {{ $linkedInAccount->isConnected() ? 'Connected' : ucfirst(str_replace('_', ' ', (string) ($linkedInAccount->status?->value ?? $linkedInAccount->status))) }}
                            </span>
                            @if ($linkedInAccount->isConnected() && $canManageLinkedIn)
                                <form method="POST" action="{{ route('app.settings.integrations.linkedin.disconnect', ['workspace_id' => $workspace->id]) }}">
                                    @csrf
                                    <input type="hidden" name="account_id" value="{{ $linkedInAccount->id }}">
                                    <button class="pl-btn-secondary">
                                        <i data-lucide="unlink" class="h-4 w-4"></i>
                                        <span>Disconnect</span>
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="p-3 text-sm text-textSecondary">No LinkedIn identities connected yet.</div>
                @endforelse
            </div>
        </section>

        @if ($accounts->filter(fn ($item) => $item->isConnected())->isEmpty())
            <section class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-base font-semibold text-textPrimary">Setup Required</h2>
                <div class="mt-4 grid gap-3">
                    <div class="rounded-md border border-border bg-background p-3">
                        <p class="text-sm font-medium text-textPrimary">1. Enable the LinkedIn app credentials</p>
                        <p class="mt-1 text-sm text-textSecondary">Set <code>LINKEDIN_CLIENT_ID</code>, <code>LINKEDIN_CLIENT_SECRET</code>, <code>LINKEDIN_REDIRECT_URI</code>, and <code>LINKEDIN_ENABLED=true</code>.</p>
                    </div>
                    <div class="rounded-md border border-border bg-background p-3">
                        <p class="text-sm font-medium text-textPrimary">2. Add this redirect URL in LinkedIn Developer</p>
                        <p class="mt-1 break-all text-sm text-textSecondary">{{ $configuredRedirectUri ?: 'No redirect URL configured.' }}</p>
                    </div>
                    <div class="rounded-md border border-border bg-background p-3">
                        <p class="text-sm font-medium text-textPrimary">3. Connect as a workspace owner or admin</p>
                        <p class="mt-1 text-sm text-textSecondary">{{ $canManageLinkedIn ? 'Your role can connect LinkedIn once the integration is enabled.' : 'Ask a workspace owner or admin to connect the LinkedIn account.' }}</p>
                    </div>
                </div>
            </section>
        @endif

        <section class="rounded-lg border border-border bg-surface p-5">
            <h2 class="text-base font-semibold text-textPrimary">Publishing Safety</h2>
            <div class="mt-4 grid gap-3 md:grid-cols-2">
                <div class="rounded-md border border-border bg-background p-3">
                    <p class="text-xs font-medium uppercase tracking-wide text-textFaint">Integration</p>
                    <p class="mt-1 text-sm text-textPrimary">{{ $linkedinEnabled ? 'Enabled' : 'Disabled' }}</p>
                </div>
                <div class="rounded-md border border-border bg-background p-3">
                    <p class="text-xs font-medium uppercase tracking-wide text-textFaint">Publishing</p>
                    <p class="mt-1 text-sm text-textPrimary">{{ $publishingEnabled ? 'Enabled' : 'Disabled by default' }}</p>
                </div>
            </div>
            <p class="mt-4 text-sm text-textSecondary">Human approval is required before posts can be scheduled or published. The MVP supports personal profile text and article shares first.</p>
        </section>
    </div>
@endsection
