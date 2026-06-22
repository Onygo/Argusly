@extends('layouts.app', ['title' => 'Instagram Integration'])

@section('content')
    <div class="mx-auto max-w-4xl space-y-6">
        <div>
            <p class="text-sm text-textSecondary">Settings / Integrations</p>
            <h1 class="text-xl font-semibold text-textPrimary">Instagram</h1>
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
                        <i data-lucide="instagram" class="h-4 w-4 text-textSecondary"></i>
                    </span>
                    <div>
                        <h2 class="text-base font-semibold text-textPrimary">Connection</h2>
                        <p class="mt-1 text-sm text-textSecondary">
                            {{ $accounts->filter(fn ($item) => $item->isConnected())->count() }} connected Instagram Professional account(s) in this workspace.
                        </p>
                    </div>
                </div>
                <span class="rounded-full border border-border bg-background px-3 py-1 text-xs font-medium text-textSecondary">
                    {{ $accounts->count() }} account(s)
                </span>
            </div>

            <div class="mt-5 flex flex-wrap gap-3">
                @if ($instagramEnabled && $canManageInstagram)
                    <a href="{{ route('app.settings.integrations.instagram.connect', ['workspace_id' => $workspace->id]) }}" class="pl-btn-primary">
                        <i data-lucide="link" class="h-4 w-4"></i>
                        <span>Connect Instagram</span>
                    </a>
                @else
                    <button class="pl-btn-secondary opacity-60" disabled>
                        <i data-lucide="link" class="h-4 w-4"></i>
                        <span>Connect Instagram</span>
                    </button>
                @endif
            </div>

            <div class="mt-5 divide-y divide-border rounded-md border border-border bg-background">
                @forelse ($accounts as $instagramAccount)
                    <div class="flex flex-col gap-3 p-3 md:flex-row md:items-center md:justify-between">
                        <div>
                            <p class="text-sm font-medium text-textPrimary">{{ $instagramAccount->display_name }}</p>
                            <p class="mt-1 text-xs text-textSecondary">
                                Instagram connected
                                · {{ ucfirst((string) $instagramAccount->account_type) }} account
                                · {{ $instagramAccount->platform_account_id ?: 'OAuth account' }}
                            </p>
                            <p class="mt-1 text-xs text-textSecondary">Token {{ $instagramAccount->expires_at?->isPast() ? 'expired' : 'active' }}{{ $instagramAccount->expires_at ? ' until '.$instagramAccount->expires_at->toDayDateTimeString() : '' }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="rounded-full border border-border bg-surface px-3 py-1 text-xs font-medium text-textSecondary">
                                {{ $instagramAccount->isConnected() ? 'Connected' : ucfirst(str_replace('_', ' ', (string) ($instagramAccount->status?->value ?? $instagramAccount->status))) }}
                            </span>
                            @if ($instagramAccount->isConnected() && $canManageInstagram)
                                <form method="POST" action="{{ route('app.settings.integrations.instagram.disconnect', ['workspace_id' => $workspace->id]) }}">
                                    @csrf
                                    <input type="hidden" name="account_id" value="{{ $instagramAccount->id }}">
                                    <button class="pl-btn-secondary">
                                        <i data-lucide="unlink" class="h-4 w-4"></i>
                                        <span>Disconnect</span>
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="p-3 text-sm text-textSecondary">No Instagram Professional accounts connected yet.</div>
                @endforelse
            </div>
        </section>

        <section class="rounded-lg border border-border bg-surface p-5">
            <h2 class="text-base font-semibold text-textPrimary">Setup Required</h2>
            <div class="mt-4 grid gap-3">
                <div class="rounded-md border border-border bg-background p-3">
                    <p class="text-sm font-medium text-textPrimary">1. Enable Meta app credentials</p>
                    <p class="mt-1 text-sm text-textSecondary">Set <code>META_CLIENT_ID</code>, <code>META_CLIENT_SECRET</code>, <code>META_REDIRECT_URI</code>, and optionally <code>META_GRAPH_API_VERSION</code>.</p>
                </div>
                <div class="rounded-md border border-border bg-background p-3">
                    <p class="text-sm font-medium text-textPrimary">2. Add this redirect URL in Meta Developers</p>
                    <p class="mt-1 break-all text-sm text-textSecondary">{{ $configuredRedirectUri ?: 'No redirect URL configured.' }}</p>
                </div>
                <div class="rounded-md border border-border bg-background p-3">
                    <p class="text-sm font-medium text-textPrimary">3. Use an Instagram Professional account</p>
                    <p class="mt-1 text-sm text-textSecondary">Instagram publishing is only available for Business and Creator accounts. Personal profiles are not supported for automated publishing.</p>
                </div>
            </div>
        </section>

        <section class="rounded-lg border border-border bg-surface p-5">
            <h2 class="text-base font-semibold text-textPrimary">Publishing Safety</h2>
            <p class="mt-2 text-sm text-textSecondary">Human approval is required before posts can be scheduled or published. The MVP supports single image feed posts with caption and hashtags.</p>
        </section>
    </div>
@endsection
