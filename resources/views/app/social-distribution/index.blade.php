@extends('layouts.app', ['title' => 'Distribution', 'pageWidth' => 'wide'])

@section('content')
    @php
        $linkedinRenderer = app(\App\Services\SocialDistribution\LinkedInPostTextRenderer::class);
        $capabilities = app(\App\Services\SocialDistribution\SocialPlatformCapabilities::class);
        $linkedinDisclaimerEnabled = $linkedinRenderer->disclaimerEnabled();
        $linkedinDisclaimerText = $linkedinRenderer->disclaimerText();
        $connectedAccounts = $accounts->filter(fn ($account) => $account->isConnected());
        $pendingAccounts = $accounts->filter(fn ($account) => ($account->status?->value ?? $account->status) === 'oauth_pending');
        $attentionVariants = $variants->filter(fn ($variant) => in_array(($variant->status?->value ?? $variant->status), ['failed', 'changes_requested'], true));
        $pendingVariants = $variants->filter(fn ($variant) => in_array(($variant->status?->value ?? $variant->status), ['generation_requested', 'generating', 'draft', 'pending_approval'], true));
        $approvedVariants = $variants->filter(fn ($variant) => ($variant->status?->value ?? $variant->status) === 'approved');
        $scheduledPublications = $publications->filter(fn ($publication) => ($publication->status?->value ?? $publication->status) === 'scheduled');
        $copyVariants = $variants->filter(fn ($variant) => trim($variant->bodyWithoutRepeatedHook()) !== '');
        $setupVariants = $variants->reject(fn ($variant) => trim($variant->bodyWithoutRepeatedHook()) !== '');
        $rt = function (string $value, array $replace = []): string {
            $key = 'app.runtime.'.$value;
            $translated = __($key, $replace);

            return $translated === $key ? strtr($value, collect($replace)->mapWithKeys(fn ($replacement, $placeholder) => [':'.$placeholder => $replacement])->all()) : $translated;
        };
        $formatStatus = fn ($status) => $rt((string) str($status?->value ?? $status)->replace('_', ' ')->title());
        $platformValue = fn ($model) => (string) ($model->platform?->value ?? $model->platform);
        $platformLabel = fn ($platform) => $capabilities->label($platform);
        $postLabel = fn ($platform) => $capabilities->postLabel($platform);
        $publishingEnabled = fn ($platform) => match ((string) $platform) {
            'instagram' => (bool) config('services.meta.enabled'),
            default => (bool) config('services.linkedin.publishing_enabled'),
        };
    @endphp

    <div class="space-y-6">
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
                <p class="text-sm text-textSecondary">{{ $rt('Agentic Marketing') }}</p>
                <h1 class="text-xl font-semibold text-textPrimary">{{ $rt('Distribution') }}</h1>
                <p class="mt-1 max-w-3xl text-sm text-textSecondary">{{ $rt('Plan LinkedIn and Instagram variants, approvals, scheduling, account targeting, and campaign distribution without bypassing human review.') }}</p>
            </div>
            <form method="POST" action="{{ route('app.agentic-marketing.distribution.linkedin.connect') }}" class="grid gap-2 sm:grid-cols-[minmax(12rem,1fr)_9rem_9rem_auto]">
                @csrf
                <input name="display_name" class="pl-input" placeholder="{{ $rt('LinkedIn account name') }}">
                <select name="account_type" class="pl-input" aria-label="{{ $rt('Account type') }}">
                    <option value="person">{{ $rt('Personal') }}</option>
                    <option value="organization">{{ $rt('Company') }}</option>
                </select>
                <input name="labels" class="pl-input" placeholder="{{ $rt('Founder, Brand') }}">
                <button class="pl-btn-primary min-w-40 justify-center whitespace-nowrap">
                    <i data-lucide="plus" class="h-4 w-4"></i>
                    <span>{{ $rt('Add LinkedIn') }}</span>
                </button>
            </form>
            <a href="{{ route('app.settings.integrations.instagram', ['workspace_id' => $workspace->id]) }}" class="pl-btn-primary min-w-40 justify-center whitespace-nowrap">
                <i data-lucide="instagram" class="h-4 w-4"></i>
                <span>{{ $rt('Connect Instagram') }}</span>
            </a>
        </div>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        @if ($errors->any())
            <div class="rounded-md border border-danger/20 bg-danger/5 px-4 py-3 text-sm text-danger">
                {{ $errors->first() }}
            </div>
        @endif

        @if ($linkedinDisclaimerEnabled)
            <x-alert>
                {{ $linkedinDisclaimerText }}
            </x-alert>
        @endif

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <section class="rounded-lg border border-border bg-surface p-4">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-xs font-medium uppercase tracking-wide text-textFaint">{{ $rt('Accounts') }}</p>
                    <i data-lucide="users" class="h-4 w-4 text-textFaint"></i>
                </div>
                <p class="mt-2 text-2xl font-semibold text-textPrimary">{{ $accounts->count() }}</p>
                <p class="mt-1 text-xs text-textSecondary">{{ $rt(':connected connected, :pending pending OAuth', ['connected' => $connectedAccounts->count(), 'pending' => $pendingAccounts->count()]) }}</p>
            </section>
            <section class="rounded-lg border border-border bg-surface p-4">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-xs font-medium uppercase tracking-wide text-textFaint">{{ $rt('Campaigns') }}</p>
                    <i data-lucide="network" class="h-4 w-4 text-textFaint"></i>
                </div>
                <p class="mt-2 text-2xl font-semibold text-textPrimary">{{ $campaigns->count() }}</p>
                <p class="mt-1 text-xs text-textSecondary">{{ $rt('Recent campaign distribution scope') }}</p>
            </section>
            <section class="rounded-lg border border-border bg-surface p-4">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-xs font-medium uppercase tracking-wide text-textFaint">{{ $rt('Variants') }}</p>
                    <i data-lucide="copy-plus" class="h-4 w-4 text-textFaint"></i>
                </div>
                <p class="mt-2 text-2xl font-semibold text-textPrimary">{{ $variants->count() }}</p>
                <p class="mt-1 text-xs text-textSecondary">{{ $rt(':approved approved, :pending in progress', ['approved' => $approvedVariants->count(), 'pending' => $pendingVariants->count()]) }}</p>
            </section>
            <section class="rounded-lg border border-border bg-surface p-4">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-xs font-medium uppercase tracking-wide text-textFaint">{{ $rt('Needs attention') }}</p>
                    <i data-lucide="circle-alert" class="h-4 w-4 text-textFaint"></i>
                </div>
                <p class="mt-2 text-2xl font-semibold text-textPrimary">{{ $attentionVariants->count() + $publications->where('status.value', 'failed')->count() }}</p>
                <p class="mt-1 text-xs text-textSecondary">{{ $rt(':count scheduled item(s)', ['count' => $scheduledPublications->count()]) }}</p>
            </section>
        </div>

        <section class="rounded-lg border border-border bg-surface">
            <div class="flex flex-col gap-2 border-b border-border p-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="text-base font-semibold text-textPrimary">{{ $rt('Social Accounts') }}</h2>
                    <span class="sr-only">{{ $rt('LinkedIn Accounts') }}</span>
                    <p class="mt-1 text-sm text-textSecondary">{{ $rt('Manage workspace-owned identities for multi-actor distribution, approval, tone, and posting limits.') }}</p>
                </div>
                <span class="rounded-full border border-border bg-background px-3 py-1 text-xs font-medium text-textSecondary">{{ $rt(':count account(s)', ['count' => $accounts->count()]) }}</span>
            </div>
            <div class="divide-y divide-border">
                @forelse ($accounts as $account)
                    @php
                        $avatarUrl = $account->avatarUrl();
                        $accountLabels = $account->labels();
                        $publishingRules = (array) $account->publishing_rules;
                        $permissions = (array) data_get($publishingRules, 'permissions', ['draft', 'schedule', 'publish']);
                        $approvalPolicy = (string) data_get($publishingRules, 'approval_policy', data_get($publishingRules, 'approval_required', true) ? 'required' : 'optional');
                        $postingLimit = data_get($account->rate_limit_policy, 'posting_limit_per_day');
                    @endphp
                    <div class="grid gap-4 p-4 xl:grid-cols-[1fr_2fr] xl:items-start">
                        <div class="flex items-start gap-3">
                            @if ($avatarUrl)
                                <img
                                    src="{{ $avatarUrl }}"
                                    alt="{{ $account->display_name }}"
                                    class="h-10 w-10 shrink-0 rounded-md border border-border object-cover"
                                    loading="lazy"
                                    referrerpolicy="no-referrer"
                                >
                            @else
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md border border-border bg-background text-xs font-semibold text-textSecondary">
                                    {{ $account->initials() }}
                                </span>
                            @endif
                            <div>
                                <p class="text-sm font-semibold text-textPrimary">{{ $account->display_name }}</p>
                                <p class="mt-1 text-xs text-textSecondary">{{ $platformLabel($account->platform) }} · {{ $formatStatus($account->status) }} · {{ $rt((string) str($account->account_type)->replace('_', ' ')->title()) }} · {{ $account->ownerLabel() }}</p>
                                <p class="mt-1 text-xs text-textFaint">{{ $account->platform_account_id ?: $rt('OAuth placeholder') }}</p>
                                @if ($accountLabels !== [])
                                    <div class="mt-2 flex flex-wrap gap-1.5">
                                        @foreach ($accountLabels as $label)
                                            <span class="rounded-full border border-border bg-background px-2 py-0.5 text-xs text-textSecondary">{{ $label }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                        <div class="grid gap-3">
                            <form method="POST" action="{{ route('app.agentic-marketing.distribution.accounts.update', ['account' => $account, 'workspace_id' => $workspace->id]) }}" class="grid gap-3">
                                @csrf
                                @method('PUT')
                                <div class="grid gap-2 md:grid-cols-[minmax(10rem,1fr)_8rem_10rem]">
                                    <label>
                                        <span class="sr-only">{{ $rt('Account name') }}</span>
                                        <input id="account-name-{{ $account->id }}" name="display_name" value="{{ old('display_name', $account->display_name) }}" class="pl-input" maxlength="180" required>
                                    </label>
                                    <label>
                                        <span class="sr-only">{{ $rt('Type') }}</span>
                                        <select name="account_type" class="pl-input">
                                            <option value="person" @selected(old('account_type', $account->account_type) === 'person')>{{ $rt('Personal') }}</option>
                                            <option value="organization" @selected(old('account_type', $account->account_type) === 'organization')>{{ $rt('Company') }}</option>
                                            <option value="business" @selected(old('account_type', $account->account_type) === 'business')>{{ $rt('Business') }}</option>
                                            <option value="creator" @selected(old('account_type', $account->account_type) === 'creator')>{{ $rt('Creator') }}</option>
                                        </select>
                                    </label>
                                    <label>
                                        <span class="sr-only">{{ $rt('Owner') }}</span>
                                        <select name="owner_user_id" class="pl-input">
                                            <option value="">{{ $rt('Workspace') }}</option>
                                            @foreach ($workspaceUsers as $workspaceUser)
                                                <option value="{{ $workspaceUser->id }}" @selected((string) old('owner_user_id', $account->user_id) === (string) $workspaceUser->id)>{{ $workspaceUser->name ?: $workspaceUser->email }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                </div>
                                <div class="grid gap-2 md:grid-cols-[minmax(10rem,1fr)_minmax(10rem,1fr)]">
                                    <input name="labels" value="{{ old('labels', implode(', ', $accountLabels)) }}" class="pl-input" maxlength="180" placeholder="{{ $rt('Founder, Brand, Sales') }}">
                                    <input name="tone_profile" value="{{ old('tone_profile', $account->toneProfile()) }}" class="pl-input" maxlength="500" placeholder="{{ $rt('Founder voice, strategic and direct') }}">
                                </div>
                                <div class="grid gap-2 md:grid-cols-[10rem_10rem_10rem_auto] md:items-center">
                                    <select name="engagement_role" class="pl-input" aria-label="{{ $rt('Engagement role') }}">
                                        <option value="primary_publisher" @selected(old('engagement_role', $account->engagementRole() ?: 'primary_publisher') === 'primary_publisher')>{{ $rt('Publisher') }}</option>
                                        <option value="amplifier" @selected(old('engagement_role', $account->engagementRole()) === 'amplifier')>{{ $rt('Amplifier') }}</option>
                                        <option value="commenter" @selected(old('engagement_role', $account->engagementRole()) === 'commenter')>{{ $rt('Commenter') }}</option>
                                        <option value="reviewer" @selected(old('engagement_role', $account->engagementRole()) === 'reviewer')>{{ $rt('Reviewer') }}</option>
                                        <option value="observer" @selected(old('engagement_role', $account->engagementRole()) === 'observer')>{{ $rt('Observer') }}</option>
                                    </select>
                                    <select name="approval_policy" class="pl-input" aria-label="{{ $rt('Approval policy') }}">
                                        <option value="required" @selected(old('approval_policy', $approvalPolicy) === 'required')>{{ $rt('Approval') }}</option>
                                        <option value="optional" @selected(old('approval_policy', $approvalPolicy) === 'optional')>{{ $rt('Optional') }}</option>
                                    </select>
                                    <input type="number" name="posting_limit_per_day" value="{{ old('posting_limit_per_day', $postingLimit) }}" min="1" max="50" class="pl-input" placeholder="{{ $rt('Posts/day') }}">
                                    <div class="flex flex-wrap gap-3 text-sm text-textSecondary">
                                        <label class="inline-flex items-center gap-2">
                                            <input type="checkbox" name="can_schedule" value="1" @checked(in_array('schedule', $permissions, true))>
                                            <span>{{ $rt('Schedule') }}</span>
                                        </label>
                                        <label class="inline-flex items-center gap-2">
                                            <input type="checkbox" name="can_publish" value="1" @checked(in_array('publish', $permissions, true))>
                                            <span>{{ $rt('Publish') }}</span>
                                        </label>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <button class="pl-btn-secondary">
                                        <i data-lucide="save" class="h-4 w-4"></i>
                                        <span>{{ $rt('Save account') }}</span>
                                    </button>
                                    @if (! $account->isPublishable())
                                        <span class="inline-flex items-center gap-1.5 rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-medium text-amber-800">
                                            <i data-lucide="lock-keyhole" class="h-3.5 w-3.5"></i>
                                            {{ $rt('Not publishable') }}
                                        </span>
                                    @endif
                                </div>
                            </form>
                            <form method="POST" action="{{ route('app.agentic-marketing.distribution.accounts.destroy', ['account' => $account, 'workspace_id' => $workspace->id]) }}">
                                @csrf
                                @method('DELETE')
                                <button class="pl-btn-secondary text-danger hover:bg-danger/5" onclick="return confirm('{{ $rt('Remove this social account? Scheduled publications keep their historical account reference.') }}')">
                                    <i data-lucide="trash-2" class="h-4 w-4"></i>
                                    <span>{{ $rt('Remove') }}</span>
                                </button>
                            </form>
                        </div>
                    </div>
                @empty
                    <div class="flex min-h-32 flex-col items-center justify-center px-4 text-center">
                        <i data-lucide="send" class="h-8 w-8 text-textFaint"></i>
                        <p class="mt-3 text-sm font-medium text-textPrimary">{{ $rt('No social accounts yet') }}</p>
                        <p class="mt-1 max-w-md text-sm text-textSecondary">{{ $rt('Connect LinkedIn or Instagram before scheduling social distribution.') }}</p>
                    </div>
                @endforelse
            </div>
        </section>

        <section class="rounded-lg border border-border bg-surface">
            <div class="border-b border-border p-4">
                <h2 class="text-base font-semibold text-textPrimary">{{ $rt('Create social copy') }}</h2>
                <p class="mt-1 text-sm text-textSecondary">{{ $rt('Choose one source for new variants. Both paths stay review-gated before publishing.') }}</p>
            </div>
            <div class="divide-y divide-border">
                <details>
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 p-4">
                        <div>
                            <h3 class="text-sm font-semibold text-textPrimary">{{ $rt('Generate Social Variants') }}</h3>
                            <span class="sr-only">{{ $rt('Generate LinkedIn Variants') }}</span>
                            <p class="mt-1 text-sm text-textSecondary">{{ $rt('Create generation requests from approved campaign context. Copy remains review-gated.') }}</p>
                        </div>
                        <i data-lucide="chevron-down" class="h-4 w-4 text-textFaint"></i>
                    </summary>
                    <form method="POST" action="{{ route('app.agentic-marketing.distribution.variants.request') }}" class="grid gap-4 px-4 pb-4">
                        @csrf
                        <div class="grid gap-4 lg:grid-cols-3">
                            <label>
                                <span class="text-xs font-medium text-textSecondary">{{ $rt('Campaign') }}</span>
                                <select name="campaign_id" class="pl-input mt-1" required>
                                    <option value="">{{ $rt('Select campaign') }}</option>
                                    @foreach ($campaigns as $campaign)
                                        <option value="{{ $campaign->id }}">{{ $campaign->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>
                                <span class="text-xs font-medium text-textSecondary">{{ $rt('Channel') }}</span>
                                <select name="platform" class="pl-input mt-1" required>
                                    <option value="linkedin">{{ $rt('LinkedIn Post') }}</option>
                                    <option value="instagram">{{ $rt('Instagram Post') }}</option>
                                </select>
                            </label>
                            <label>
                                <span class="text-xs font-medium text-textSecondary">{{ $rt('Social account') }}</span>
                                <select name="social_account_id" class="pl-input mt-1">
                                    <option value="">{{ $rt('Assign later') }}</option>
                                    @foreach ($accounts as $account)
                                        <option value="{{ $account->id }}">{{ $account->display_name }} · {{ $formatStatus($account->status) }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_8rem_8rem_minmax(0,1fr)]">
                            <label>
                                <span class="text-xs font-medium text-textSecondary">{{ $rt('Post type') }}</span>
                                <select name="post_type" class="pl-input mt-1" required>
                                    @foreach ($postTypes as $postType)
                                        <option value="{{ $postType }}">{{ $rt((string) str($postType)->replace('_', ' ')->title()) }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>
                                <span class="text-xs font-medium text-textSecondary">{{ $rt('Variants') }}</span>
                                <input type="number" name="variant_count" min="1" max="5" value="3" class="pl-input mt-1">
                            </label>
                            <label>
                                <span class="text-xs font-medium text-textSecondary">{{ $rt('Language') }}</span>
                                <select name="language" class="pl-input mt-1" required>
                                    <option value="nl" selected>NL</option>
                                    <option value="en">EN</option>
                                </select>
                            </label>
                            <label>
                                <span class="text-xs font-medium text-textSecondary">{{ $rt('Article URL') }}</span>
                                <input type="url" name="source_url" class="pl-input mt-1" maxlength="500" placeholder="https://example.com/artikel">
                            </label>
                        </div>
                        <label>
                            <span class="text-xs font-medium text-textSecondary">{{ $rt('Hashtags') }}</span>
                            <input name="hashtags" class="pl-input mt-1" maxlength="240" placeholder="#AIVisibility #ContentMarketing #B2B">
                        </label>
                        <label>
                            <span class="text-xs font-medium text-textSecondary">{{ $rt('Image URL') }}</span>
                            <input type="url" name="media_url" class="pl-input mt-1" maxlength="1000" placeholder="{{ $rt('Required for Instagram posts') }}">
                        </label>
                        <details class="rounded-md border border-border bg-background p-3">
                            <summary class="cursor-pointer text-xs font-semibold text-textSecondary">Tracking parameters</summary>
                            <div class="mt-3 grid gap-3">
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <label>
                                        <span class="text-xs font-medium text-textSecondary">UTM source</span>
                                        <input name="utm_source" class="pl-input mt-1" maxlength="120" placeholder="linkedin">
                                    </label>
                                    <label>
                                        <span class="text-xs font-medium text-textSecondary">UTM medium</span>
                                        <input name="utm_medium" class="pl-input mt-1" maxlength="120" placeholder="social">
                                    </label>
                                </div>
                                <label>
                                    <span class="text-xs font-medium text-textSecondary">UTM campaign</span>
                                    <input name="utm_campaign" class="pl-input mt-1" maxlength="180" placeholder="q3-ai-authority">
                                </label>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <label>
                                        <span class="text-xs font-medium text-textSecondary">UTM content</span>
                                        <input name="utm_content" class="pl-input mt-1" maxlength="180" placeholder="thought-leadership">
                                    </label>
                                    <label>
                                        <span class="text-xs font-medium text-textSecondary">UTM term</span>
                                        <input name="utm_term" class="pl-input mt-1" maxlength="180" placeholder="agentic-marketing">
                                    </label>
                                </div>
                            </div>
                        </details>
                        <button class="pl-btn-primary w-full">
                            <i data-lucide="sparkles" class="h-4 w-4"></i>
                            <span>{{ $rt('Queue generation') }}</span>
                        </button>
                    </form>
                    @if ($accounts->isEmpty())
                        <div class="border-t border-border px-4 py-3 text-sm text-textSecondary">
                            {{ $rt('Connect or add a social account before scheduling. OAuth can be completed when credentials are configured.') }}
                        </div>
                    @endif
                </details>

                <details>
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 p-4">
                        <div>
                            <h3 class="text-sm font-semibold text-textPrimary">{{ $rt('Create From Content') }}</h3>
                            <p class="mt-1 text-sm text-textSecondary">{{ $rt('Prepare a LinkedIn draft and 3 to 5 variants from an existing article. Publishing remains approval-gated.') }}</p>
                        </div>
                        <i data-lucide="chevron-down" class="h-4 w-4 text-textFaint"></i>
                    </summary>
                    <form method="POST" action="{{ route('app.agentic-marketing.distribution.content-drafts.store') }}" class="grid gap-4 px-4 pb-4">
                        @csrf
                        <div class="grid gap-4 lg:grid-cols-2">
                            <label>
                                <span class="text-xs font-medium text-textSecondary">{{ $rt('Content') }}</span>
                                <select name="content_id" class="pl-input mt-1" required>
                                    <option value="">{{ $rt('Select content') }}</option>
                                    @foreach ($contentItems as $contentItem)
                                        <option value="{{ $contentItem->id }}">{{ $contentItem->title }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>
                                <span class="text-xs font-medium text-textSecondary">{{ $rt('LinkedIn account') }}</span>
                                <select name="social_account_id" class="pl-input mt-1">
                                    <option value="">{{ $rt('Assign later') }}</option>
                                    @foreach ($accounts as $account)
                                        <option value="{{ $account->id }}">{{ $account->display_name }} · {{ $formatStatus($account->status) }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                        <div class="grid gap-4 lg:grid-cols-2">
                            <label>
                                <span class="text-xs font-medium text-textSecondary">{{ $rt('Campaign') }}</span>
                                <select name="campaign_id" class="pl-input mt-1">
                                    <option value="">{{ $rt('Optional') }}</option>
                                    @foreach ($campaigns as $campaign)
                                        <option value="{{ $campaign->id }}">{{ $campaign->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                        <div class="grid gap-4 lg:grid-cols-2">
                            <label>
                                <span class="text-xs font-medium text-textSecondary">{{ $rt('Audience') }}</span>
                                <input name="target_audience" class="pl-input mt-1" maxlength="180" placeholder="{{ $rt('B2B marketing leaders') }}">
                            </label>
                            <label>
                                <span class="text-xs font-medium text-textSecondary">{{ $rt('Tone') }}</span>
                                <input name="tone_of_voice" class="pl-input mt-1" maxlength="180" placeholder="{{ $rt('Practical and direct') }}">
                            </label>
                        </div>
                        <div class="grid gap-4 lg:grid-cols-[8rem_1fr]">
                            <label>
                                <span class="text-xs font-medium text-textSecondary">{{ $rt('Language') }}</span>
                                <select name="language" class="pl-input mt-1" required>
                                    <option value="nl" selected>NL</option>
                                    <option value="en">EN</option>
                                </select>
                            </label>
                            <label>
                                <span class="text-xs font-medium text-textSecondary">{{ $rt('Article URL') }}</span>
                                <input type="url" name="source_url" class="pl-input mt-1" maxlength="500" placeholder="{{ $rt('Defaults to the selected article canonical URL') }}">
                            </label>
                        </div>
                        <label>
                            <span class="text-xs font-medium text-textSecondary">{{ $rt('Hashtags') }}</span>
                            <input name="hashtags" class="pl-input mt-1" maxlength="240" placeholder="#AIVisibility #ContentMarketing #B2B">
                        </label>
                        <details class="rounded-md border border-border bg-background p-3">
                            <summary class="cursor-pointer text-xs font-semibold text-textSecondary">Tracking parameters</summary>
                            <div class="mt-3 grid gap-3">
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <label>
                                        <span class="text-xs font-medium text-textSecondary">UTM source</span>
                                        <input name="utm_source" class="pl-input mt-1" maxlength="120" placeholder="linkedin">
                                    </label>
                                    <label>
                                        <span class="text-xs font-medium text-textSecondary">UTM medium</span>
                                        <input name="utm_medium" class="pl-input mt-1" maxlength="120" placeholder="social">
                                    </label>
                                </div>
                                <label>
                                    <span class="text-xs font-medium text-textSecondary">UTM campaign</span>
                                    <input name="utm_campaign" class="pl-input mt-1" maxlength="180" placeholder="q3-ai-authority">
                                </label>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <label>
                                        <span class="text-xs font-medium text-textSecondary">UTM content</span>
                                        <input name="utm_content" class="pl-input mt-1" maxlength="180" placeholder="article-variant">
                                    </label>
                                    <label>
                                        <span class="text-xs font-medium text-textSecondary">UTM term</span>
                                        <input name="utm_term" class="pl-input mt-1" maxlength="180" placeholder="agentic-marketing">
                                    </label>
                                </div>
                            </div>
                        </details>
                        <button class="pl-btn-primary w-full">
                            <i data-lucide="file-plus-2" class="h-4 w-4"></i>
                            <span>{{ $rt('Create draft') }}</span>
                        </button>
                    </form>
                </details>
            </div>
        </section>

        <div class="grid gap-4 xl:grid-cols-[0.85fr_1.15fr]">

            <details class="rounded-lg border border-border bg-surface">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 p-4">
                    <div>
                        <h2 class="text-base font-semibold text-textPrimary">{{ $rt('Campaign Timeline') }}</h2>
                        <p class="mt-1 text-sm text-textSecondary">{{ $rt('Scheduled, queued, and published distribution grouped by publish date.') }}</p>
                    </div>
                    <span class="inline-flex items-center gap-2 rounded-full border border-border bg-background px-3 py-1 text-xs font-medium text-textSecondary">
                        {{ $rt(':count item(s)', ['count' => $timeline->flatten(1)->count()]) }}
                        <i data-lucide="chevron-down" class="h-3.5 w-3.5 text-textFaint"></i>
                    </span>
                </summary>
                <div class="min-h-64 p-4">
                    @forelse ($timeline as $date => $items)
                        <div class="grid gap-3 border-l border-border pb-5 pl-4 last:pb-0">
                            <div class="-ml-[1.45rem] flex items-center gap-3">
                                <span class="h-3 w-3 rounded-full border border-primary bg-surface"></span>
                                <p class="text-sm font-semibold text-textPrimary">{{ $date }}</p>
                            </div>
                            <div class="grid gap-2">
                                @foreach ($items as $item)
                                    @php
                                        $timelineTitle = trim((string) (
                                            $item->campaign?->name
                                            ?: $item->variant?->hook
                                            ?: data_get($item->payload_snapshot, 'title')
                                            ?: $postLabel($item->platform)
                                        ));
                                        $timelineText = trim((string) data_get($item->payload_snapshot, 'publishing_text', ''));
                                        if ($timelineText === '' && $item->variant) {
                                            $timelineText = $item->variant->publishingText();
                                        }
                                        $timelineUrl = trim((string) ($item->remote_url ?: data_get($item->response_snapshot, 'remote_url', '')));
                                    @endphp
                                    <div class="rounded-md border border-border bg-background p-3">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <p class="text-sm font-medium text-textPrimary">{{ $timelineTitle }}</p>
                                            <span class="rounded-full bg-surfaceMuted px-2 py-1 text-xs text-textSecondary">{{ $formatStatus($item->status) }}</span>
                                        </div>
                                        <p class="mt-1 text-xs text-textSecondary">
                                            {{ $item->socialAccount?->display_name ?? $rt('No account') }}
                                            · {{ ($item->published_at ?? $item->scheduled_for)?->copy()->timezone($scheduleTimezone)->format('H:i') }}
                                        </p>
                                        @if ($item->campaign)
                                            <p class="mt-1 text-xs text-textFaint">{{ $rt('Campaign') }} · {{ $item->campaign->name }}</p>
                                        @endif
                                        @if ($timelineText !== '')
                                            <p class="mt-2 line-clamp-2 text-xs leading-5 text-textSecondary">{{ $timelineText }}</p>
                                        @endif
                                        @if ($timelineUrl !== '')
                                            <a href="{{ $timelineUrl }}" target="_blank" rel="noopener" class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
                                                <i data-lucide="external-link" class="h-3.5 w-3.5"></i>
                                                <span>{{ $rt('View on :platform', ['platform' => $platformLabel($item->platform)]) }}</span>
                                            </a>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <div class="flex min-h-52 flex-col items-center justify-center rounded-md border border-dashed border-border bg-background px-4 text-center">
                            <i data-lucide="calendar-clock" class="h-8 w-8 text-textFaint"></i>
                            <p class="mt-3 text-sm font-medium text-textPrimary">{{ $rt('No distribution activity yet') }}</p>
                            <p class="mt-1 max-w-md text-sm text-textSecondary">{{ $rt('Scheduled and published social posts will appear here once activity exists.') }}</p>
                        </div>
                    @endforelse
                </div>
            </details>
        </div>

        <details class="rounded-lg border border-border bg-surface">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 p-4">
                <h2 class="text-base font-semibold text-textPrimary">{{ $rt('Distribution Overview') }}</h2>
                <i data-lucide="chevron-down" class="h-4 w-4 text-textFaint"></i>
            </summary>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-border text-left text-sm">
                    <thead class="bg-background text-xs font-medium uppercase tracking-wide text-textSecondary">
                        <tr>
                            <th class="px-4 py-3">{{ $rt('Campaign') }}</th>
                            <th class="px-4 py-3">{{ $rt('Assets') }}</th>
                            <th class="px-4 py-3">{{ $rt('Variants') }}</th>
                            <th class="px-4 py-3">{{ $rt('Publications') }}</th>
                            <th class="px-4 py-3">{{ $rt('State') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($campaigns as $campaign)
                            <tr>
                                <td class="px-4 py-3 font-medium text-textPrimary">{{ $campaign->name }}</td>
                                <td class="px-4 py-3 text-textSecondary">{{ $campaign->contents_count }}</td>
                                <td class="px-4 py-3 text-textSecondary">{{ $campaign->social_post_variants_count }}</td>
                                <td class="px-4 py-3 text-textSecondary">{{ $campaign->social_publications_count }}</td>
                                <td class="px-4 py-3 text-textSecondary">
                                    @if (($campaign->active_social_publications_count ?? 0) > 0)
                                        {{ $rt('Scheduled activity') }}
                                    @elseif (($campaign->published_social_publications_count ?? 0) > 0)
                                        {{ $rt('Published') }}
                                    @elseif (($campaign->social_publications_count ?? 0) > 0)
                                        {{ $rt('Publication history') }}
                                    @else
                                        {{ $rt('Planning') }}
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-textSecondary">{{ $rt('No campaigns available.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </details>

        <section class="rounded-lg border border-border bg-surface">
            <div class="flex flex-col gap-2 border-b border-border p-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="text-base font-semibold text-textPrimary">{{ $rt('Social Variants') }}</h2>
                    <p class="mt-1 text-sm text-textSecondary">{{ $rt('Review draft copy first. Setup and queued items are grouped separately so the board stays scannable.') }}</p>
                </div>
                @if ($attentionVariants->isNotEmpty())
                    <span class="inline-flex items-center gap-2 rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-medium text-amber-800">
                        <i data-lucide="wrench" class="h-3.5 w-3.5"></i>
                        {{ $rt('Provider setup required') }}
                    </span>
                @endif
            </div>

            @if ($setupVariants->isNotEmpty())
                <div class="border-b border-border bg-background/60 p-4">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p class="text-sm font-semibold text-textPrimary">{{ $rt('Generation status') }}</p>
                            <p class="mt-1 text-xs text-textSecondary">{{ $rt('Items without copy are compact here until generation succeeds.') }}</p>
                        </div>
                        <span class="rounded-full border border-border bg-surface px-3 py-1 text-xs font-medium text-textSecondary">{{ $rt(':count item(s)', ['count' => $setupVariants->count()]) }}</span>
                    </div>
                    <div class="grid gap-2">
                        @foreach ($setupVariants as $variant)
                            @php
                                $status = (string) ($variant->status?->value ?? $variant->status);
                                $errorCode = (string) data_get($variant->generation_result, 'error_code', '');
                                $failureTitle = $errorCode === 'AI_GENERATION_PROVIDER_NOT_CONFIGURED'
                                    ? $rt('Generation provider required')
                                    : $rt('Generation failed');
                                $rawMessage = (string) (data_get($variant->generation_result, 'message') ?: '');
                                $message = str_contains($rawMessage, 'Array to string conversion')
                                    ? $rt('This suggestion failed because older campaign context contained technical data. Remove it and generate a fresh variant.')
                                    : ($rawMessage ?: $rt('Copy will appear here after the generation job completes.'));
                            @endphp
                            <div class="grid gap-3 rounded-md border border-border bg-surface p-3 lg:grid-cols-[15rem_minmax(0,1fr)_auto] lg:items-start">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2 lg:block">
                                        <p class="truncate text-sm font-medium text-textPrimary">{{ $rt((string) str($variant->post_type?->value ?? $variant->post_type)->replace('_', ' ')->title()) }}</p>
                                        <span @class([
                                            'rounded-full px-2 py-0.5 text-xs font-medium lg:mt-2 lg:inline-flex',
                                            'border border-amber-200 bg-amber-50 text-amber-800' => $status === 'failed',
                                            'border border-border bg-surfaceMuted text-textSecondary' => $status !== 'failed',
                                        ])>{{ $rt((string) str($status)->replace('_', ' ')->title()) }}</span>
                                    </div>
                                    <p class="mt-1 text-xs text-textSecondary">{{ $variant->campaign?->name ?? $rt('No campaign') }} · {{ $rt('Variant :number', ['number' => $variant->variant_number]) }}</p>
                                </div>
                                <div class="min-w-0">
                                    @if ($status === 'failed')
                                        <p class="text-sm font-medium text-textPrimary">{{ $failureTitle }}</p>
                                    @endif
                                    <p class="pl-line-clamp-2 mt-1 text-sm text-textSecondary">{{ $message }}</p>
                                </div>
                                <form method="POST" action="{{ route('app.agentic-marketing.distribution.variants.destroy', $variant) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-border bg-surface text-textSecondary hover:bg-danger/5 hover:text-danger" onclick="return confirm('{{ $rt('Remove this social variant? This only removes unscheduled suggestions.') }}')" aria-label="{{ $rt('Remove') }}">
                                        <i data-lucide="trash-2" class="h-4 w-4"></i>
                                    </button>
                                </form>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="grid gap-3 p-4">
                @forelse ($copyVariants as $variant)
                    @php
                        $status = (string) ($variant->status?->value ?? $variant->status);
                        $variantPlatform = $platformValue($variant);
                        $variantPostLabel = $postLabel($variant->platform);
                        $variantBody = $variant->bodyWithoutRepeatedHook();
                        $hasCopy = trim($variantBody) !== '';
                        $canApprove = $hasCopy && ! in_array($status, ['approved', 'scheduled', 'published'], true);
                        $variantPublishableAccounts = $publishableAccounts->filter(fn ($account) => $platformValue($account) === $variantPlatform);
                        $blockedReason = $variant->publishingBlockedReason();
                        $canSchedule = $hasCopy && $status === 'approved' && $variantPublishableAccounts->isNotEmpty() && $blockedReason === null;
                        $message = data_get($variant->generation_result, 'message');
                        $sourceUrl = $variant->sourceUrl();
                        $hashtagsLine = $variant->hashtagsLine();
                        $language = $variant->languageCode();
                        $scheduledPublication = $variant->publications
                            ->filter(fn ($publication) => in_array((string) ($publication->status?->value ?? $publication->status), ['scheduled', 'queued', 'rate_limited'], true))
                            ->sortByDesc(fn ($publication) => $publication->scheduled_for?->timestamp ?? 0)
                            ->first();
                        $scheduledAt = $scheduledPublication?->scheduled_for?->copy()->timezone($scheduleTimezone)->format('d-m-Y H:i');
                    @endphp
                    <article class="rounded-md border border-border bg-background p-4 lg:grid lg:grid-cols-[15rem_minmax(0,1fr)] lg:gap-x-4">
                        <div class="flex flex-wrap items-start justify-between gap-3 lg:block">
                            <div>
                                <p class="font-semibold text-textPrimary">{{ $variantPostLabel }}</p>
                                <p class="mt-1 text-xs text-textSecondary">{{ $variant->campaign?->name ?? $rt('No campaign') }} · {{ $rt('Variant :number', ['number' => $variant->variant_number]) }}</p>
                            </div>
                            <div class="lg:mt-3">
                                <span @class([
                                    'rounded-full px-2.5 py-1 text-xs font-medium inline-flex',
                                    'bg-amber-50 text-amber-800 border border-amber-200' => $status === 'failed',
                                    'bg-emerald-50 text-emerald-800 border border-emerald-200' => in_array($status, ['approved', 'scheduled', 'published'], true),
                                    'bg-surfaceMuted text-textSecondary border border-border' => ! in_array($status, ['failed', 'approved', 'scheduled', 'published'], true),
                                ])>{{ $rt((string) str($status)->replace('_', ' ')->title()) }}</span>
                                @if ($scheduledAt)
                                    <p class="mt-2 inline-flex items-center gap-1.5 text-xs text-textSecondary lg:flex">
                                        <i data-lucide="calendar-clock" class="h-3.5 w-3.5"></i>
                                        <span>{{ $rt('Scheduled for :date', ['date' => $scheduledAt]) }}</span>
                                    </p>
                                    <p class="mt-1 text-xs text-textFaint">{{ $scheduledPublication->socialAccount?->display_name ?? $rt('No account') }}</p>
                                @endif
                            </div>
                        </div>

                        @unless ($hasCopy)
                            <div class="mt-4 rounded-md border border-dashed border-border bg-surface px-4 py-3">
                                <div class="flex gap-3">
                                    <i data-lucide="{{ $status === 'failed' ? 'plug-zap' : 'loader-circle' }}" class="mt-0.5 h-4 w-4 shrink-0 text-textFaint"></i>
                                    <div>
                                        <p class="text-sm font-medium text-textPrimary">{{ $status === 'failed' ? $rt('Generation provider required') : $rt('Generation queued') }}</p>
                                        <p class="mt-1 text-sm text-textSecondary">{{ $message ?: $rt('Copy will appear here after the generation job completes.') }}</p>
                                    </div>
                                </div>
                            </div>
                        @endunless

                        @if ($hasCopy)
                            <form method="POST" action="{{ route('app.agentic-marketing.distribution.variants.update', $variant) }}" class="mt-4 grid gap-3 lg:col-start-2 lg:mt-0" data-linkedin-concept-form>
                                @csrf
                                @method('PUT')
                                <label>
                                    <span class="sr-only">{{ $variantPostLabel }} {{ $rt('copy') }}</span>
                                    <textarea name="body" class="pl-textarea min-h-56" maxlength="3000" required>{{ old('body', $variantBody) }}</textarea>
                                </label>
                                <div class="grid gap-3 sm:grid-cols-[7rem_1fr]">
                                    <label>
                                        <span class="text-xs font-medium text-textSecondary">{{ $rt('Language') }}</span>
                                        <select name="language" class="pl-input mt-1" required>
                                            <option value="nl" @selected(old('language', $language) === 'nl')>NL</option>
                                            <option value="en" @selected(old('language', $language) === 'en')>EN</option>
                                        </select>
                                    </label>
                                    <label>
                                        <span class="text-xs font-medium text-textSecondary">{{ $rt('Article URL') }}</span>
                                        <input type="url" name="source_url" value="{{ old('source_url', $sourceUrl) }}" class="pl-input mt-1" maxlength="500" placeholder="https://example.com/article">
                                    </label>
                                </div>
                                <label>
                                    <span class="text-xs font-medium text-textSecondary">{{ $rt('Hashtags') }}</span>
                                    <input name="hashtags" value="{{ old('hashtags', $hashtagsLine) }}" class="pl-input mt-1" maxlength="240" placeholder="#AIVisibility #ContentMarketing #B2B">
                                </label>
                                @if ($variant->requiresMedia())
                                    <label>
                                        <span class="text-xs font-medium text-textSecondary">{{ $rt('Image URL') }}</span>
                                        <input type="url" name="media_url" value="{{ old('media_url', data_get($variant->media_refs, '0.url')) }}" class="pl-input mt-1" maxlength="1000" placeholder="{{ $rt('Instagram posts require an image before publishing.') }}">
                                    </label>
                                    @if ($blockedReason)
                                        <p class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800">{{ $blockedReason }}</p>
                                    @endif
                                @endif
                                <label class="inline-flex items-center gap-2 text-sm text-textSecondary">
                                    <input type="checkbox" name="selected" value="1" @checked($variant->selected)>
                                    <span>{{ $rt('Select this variant for the draft') }}</span>
                                </label>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        class="pl-btn-secondary"
                                        data-linkedin-preview-trigger
                                        data-preview-mode="concept"
                                        data-preview-title="{{ $variant->campaign?->name ?? $variantPostLabel }}"
                                        data-preview-text="{{ $variant->publishingText() }}"
                                        data-preview-account="{{ $variant->socialAccount?->display_name ?? $platformLabel($variant->platform).' account' }}"
                                    >
                                        <i data-lucide="eye" class="h-4 w-4"></i>
                                        <span>Preview</span>
                                    </button>
                                    <button class="pl-btn-secondary">
                                        <i data-lucide="save" class="h-4 w-4"></i>
                                        <span>{{ $rt('Save copy') }}</span>
                                    </button>
                                </div>
                            </form>
                        @endif

                        <div class="mt-4 grid gap-2 md:grid-cols-[auto_auto_1fr_auto] lg:col-start-2">
                            @if (($variant->status?->value ?? $variant->status) !== 'approved')
                                <form method="POST" action="{{ route('app.agentic-marketing.distribution.variants.approve', $variant) }}">
                                    @csrf
                                    <button @class(['pl-btn-secondary w-full', 'opacity-50 cursor-not-allowed' => ! $canApprove]) @disabled(! $canApprove)>
                                        <i data-lucide="check" class="h-4 w-4"></i>
                                        <span>{{ $rt('Approve') }}</span>
                                    </button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('app.agentic-marketing.distribution.variants.unapprove', $variant) }}">
                                    @csrf
                                    <button class="pl-btn-secondary border-emerald-200 bg-emerald-50 text-emerald-800 hover:bg-emerald-50">
                                        <i data-lucide="rotate-ccw" class="h-4 w-4"></i>
                                        <span>{{ $rt('Undo approval') }}</span>
                                    </button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('app.agentic-marketing.distribution.variants.destroy', $variant) }}">
                                @csrf
                                @method('DELETE')
                                <button class="pl-btn-secondary text-danger hover:bg-danger/5" onclick="return confirm('{{ $rt('Remove this social variant? This only removes unscheduled suggestions.') }}')">
                                    <i data-lucide="trash-2" class="h-4 w-4"></i>
                                    <span>{{ $rt('Remove') }}</span>
                                </button>
                            </form>
                            <form
                                method="POST"
                                action="{{ route('app.agentic-marketing.distribution.variants.schedule', $variant) }}"
                                class="contents"
                                data-linkedin-preview-form
                                data-preview-mode="schedule"
                                data-preview-title="{{ $variant->campaign?->name ?? $variantPostLabel }}"
                                data-preview-text="{{ $variant->publishingText() }}"
                                data-preview-account="{{ $variant->socialAccount?->display_name ?? '' }}"
                            >
                                @csrf
                                <div class="grid gap-2 sm:grid-cols-[minmax(9rem,1fr)_minmax(12rem,1.1fr)]">
                                    <select name="social_account_id" class="pl-input" required @disabled(! $canSchedule)>
                                        @forelse ($variantPublishableAccounts as $account)
                                            <option value="{{ $account->id }}" @selected((string) $variant->social_account_id === (string) $account->id)>{{ $account->display_name }} · {{ $account->actorLabel() }}</option>
                                        @empty
                                            <option value="">{{ $rt('No connected account') }}</option>
                                        @endforelse
                                    </select>
                                    <input type="datetime-local" name="scheduled_for" class="pl-input" required @disabled(! $canSchedule)>
                                    <input type="hidden" name="timezone" value="{{ $scheduleTimezone }}" data-browser-timezone>
                                </div>
                                <button @class(['pl-btn-secondary', 'opacity-50 cursor-not-allowed' => ! $canSchedule]) @disabled(! $canSchedule)>
                                    <i data-lucide="calendar-plus" class="h-4 w-4"></i>
                                    <span>{{ $rt('Schedule') }}</span>
                                </button>
                            </form>
                        </div>
                    </article>
                @empty
                    <div class="col-span-full flex min-h-48 flex-col items-center justify-center rounded-md border border-dashed border-border bg-background px-4 text-center">
                        <i data-lucide="send" class="h-8 w-8 text-textFaint"></i>
                        <p class="mt-3 text-sm font-medium text-textPrimary">{{ $rt('No draft variants ready yet') }}</p>
                            <p class="mt-1 max-w-md text-sm text-textSecondary">{{ $rt('Generated copy will appear here once a provider returns usable social text.') }}</p>
                    </div>
                @endforelse
            </div>
        </section>

        <section class="rounded-lg border border-border bg-surface">
            <div class="border-b border-border p-4">
                <h2 class="text-base font-semibold text-textPrimary">{{ $rt('Publish Queue') }}</h2>
            </div>
            <div class="divide-y divide-border">
                @forelse ($publications as $publication)
                    @php
                        $publicationStatus = $publication->status?->value ?? $publication->status;
                        $publicationPlatform = $platformValue($publication);
                        $canPublishNow = $publishingEnabled($publicationPlatform) && in_array($publicationStatus, ['approved', 'scheduled', 'queued', 'failed'], true);
                        $publicationError = $publication->publicErrorMessage();
                    @endphp
                    <div class="flex flex-col gap-3 p-4 md:flex-row md:items-center md:justify-between">
                        <div>
                            <p class="text-sm font-medium text-textPrimary">{{ $publication->variant?->campaign?->name ?? $publication->campaign?->name ?? $postLabel($publication->platform) }}</p>
                            <p class="mt-1 text-xs text-textSecondary">{{ $formatStatus($publication->status) }} · {{ $publication->scheduled_for?->copy()->timezone($scheduleTimezone)->format('d-m-Y H:i') ?: $rt('No schedule') }}</p>
                            @if ($publicationStatus === 'failed' && filled($publicationError))
                                <p class="mt-1 max-w-xl text-xs text-danger">{{ $publicationError }}</p>
                            @endif
                        </div>
                        @php
                            $publicationPreviewText = trim((string) data_get($publication->payload_snapshot, 'publishing_text', ''));
                            if ($publicationPreviewText === '' && $publication->variant) {
                                $publicationPreviewText = $publication->variant->publishingText();
                            }
                        @endphp
                        <form
                            method="POST"
                            action="{{ route('app.agentic-marketing.distribution.publications.queue', $publication) }}"
                            data-linkedin-preview-form
                            data-preview-mode="publish"
                            data-preview-title="{{ $publication->variant?->campaign?->name ?? $publication->campaign?->name ?? $postLabel($publication->platform) }}"
                            data-preview-text="{{ $publicationPreviewText }}"
                            data-preview-account="{{ $publication->socialAccount?->display_name ?? $platformLabel($publication->platform).' account' }}"
                            data-preview-scheduled="{{ $publication->scheduled_for?->copy()->timezone($scheduleTimezone)->format('d-m-Y H:i') ?: 'Manual publish' }}"
                        >
                            @csrf
                            <button @class(['pl-btn-secondary', 'opacity-50 cursor-not-allowed' => ! $canPublishNow]) @disabled(! $canPublishNow)>
                                <i data-lucide="send" class="h-4 w-4"></i>
                                <span>{{ $rt('Publish now') }}</span>
                            </button>
                        </form>
                    </div>
                @empty
                    <div class="p-4 text-sm text-textSecondary">{{ $rt('No publications queued.') }}</div>
                @endforelse
            </div>
        </section>
    </div>

    <dialog id="linkedin-preview-dialog" class="m-auto w-[min(92vw,44rem)] rounded-lg border border-border bg-surface p-0 text-textPrimary pl-elevation-dialog backdrop:bg-black/40">
        <div class="border-b border-border px-5 py-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-textFaint">Social preview</p>
                    <h2 class="mt-1 text-base font-semibold text-textPrimary">Check voordat je publiceert</h2>
                </div>
                <button type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-border text-textSecondary hover:bg-surfaceMuted" data-linkedin-preview-close aria-label="Close preview">
                    <i data-lucide="x" class="h-4 w-4"></i>
                </button>
            </div>
        </div>

        <div class="grid gap-5 p-5 md:grid-cols-[1fr_16rem]">
            <div class="rounded-lg border border-[#d0d7de] bg-white text-[#191919]">
                <div class="flex items-start gap-3 px-4 pt-4">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-sm bg-[#0a66c2] text-white">
                        <i data-lucide="linkedin" class="h-6 w-6"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold" data-linkedin-preview-account>Social account</p>
                        <p class="text-xs text-[#666]">Argusly · Preview</p>
                        <div class="mt-1 flex items-center gap-1 text-xs text-[#666]">
                            <span data-linkedin-preview-time>Now</span>
                            <span>·</span>
                            <i data-lucide="globe-2" class="h-3 w-3"></i>
                        </div>
                    </div>
                    <i data-lucide="ellipsis" class="h-5 w-5 text-[#666]"></i>
                </div>

                <div class="px-4 py-3">
                    <p class="whitespace-pre-line break-words text-sm leading-6" data-linkedin-preview-text></p>
                </div>

                <div class="mx-4 border-t border-[#e8e8e8] py-2">
                    <div class="grid grid-cols-4 text-xs font-medium text-[#666]">
                        <span class="inline-flex items-center justify-center gap-1 py-2">
                            <i data-lucide="thumbs-up" class="h-4 w-4"></i>
                            Like
                        </span>
                        <span class="inline-flex items-center justify-center gap-1 py-2">
                            <i data-lucide="message-circle" class="h-4 w-4"></i>
                            Comment
                        </span>
                        <span class="inline-flex items-center justify-center gap-1 py-2">
                            <i data-lucide="repeat-2" class="h-4 w-4"></i>
                            Repost
                        </span>
                        <span class="inline-flex items-center justify-center gap-1 py-2">
                            <i data-lucide="send" class="h-4 w-4"></i>
                            Send
                        </span>
                    </div>
                </div>
            </div>

            <aside class="space-y-3">
                <div class="rounded-md border border-border bg-background p-3">
                    <p class="text-xs font-medium uppercase tracking-wide text-textFaint">Actie</p>
                    <p class="mt-1 text-sm font-medium text-textPrimary" data-linkedin-preview-action>Publish now</p>
                </div>
                <div class="rounded-md border border-border bg-background p-3">
                    <p class="text-xs font-medium uppercase tracking-wide text-textFaint">Campagne</p>
                    <p class="mt-1 text-sm text-textSecondary" data-linkedin-preview-title>Social post</p>
                </div>
                <p class="text-xs leading-5 text-textSecondary">Dit is een benadering van hoe het kanaal tekst, URL, media en hashtags toont. Het platform kan spacing, link cards en truncation zelf nog aanpassen.</p>
            </aside>
        </div>

        <div class="flex flex-col-reverse gap-2 border-t border-border px-5 py-4 sm:flex-row sm:justify-end">
            <button type="button" class="pl-btn-secondary justify-center" data-linkedin-preview-close>
                <i data-lucide="pencil" class="h-4 w-4"></i>
                <span>Terug naar bewerken</span>
            </button>
            <button type="button" class="pl-btn-primary justify-center" data-linkedin-preview-confirm>
                <i data-lucide="send" class="h-4 w-4"></i>
                <span>Doorgaan</span>
            </button>
        </div>
    </dialog>

    <script>
        (() => {
            const dialog = document.getElementById('linkedin-preview-dialog');
            if (!dialog) {
                return;
            }

            let pendingForm = null;

            const textNode = dialog.querySelector('[data-linkedin-preview-text]');
            const titleNode = dialog.querySelector('[data-linkedin-preview-title]');
            const accountNode = dialog.querySelector('[data-linkedin-preview-account]');
            const timeNode = dialog.querySelector('[data-linkedin-preview-time]');
            const actionNode = dialog.querySelector('[data-linkedin-preview-action]');
            const confirmButton = dialog.querySelector('[data-linkedin-preview-confirm]');
            const disclaimerEnabled = @json($linkedinDisclaimerEnabled);
            const disclaimerText = @json($linkedinDisclaimerText);
            const browserTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone || @json($scheduleTimezone);

            document.querySelectorAll('[data-browser-timezone]').forEach((field) => {
                field.value = browserTimezone;
            });

            const selectedOptionText = (form, name) => {
                const field = form.querySelector(`[name="${name}"]`);
                if (!field || !field.options || field.selectedIndex < 0) {
                    return '';
                }

                return field.options[field.selectedIndex]?.textContent?.trim() || '';
            };

            const formatSchedule = (value) => {
                if (!value) {
                    return '';
                }

                const date = new Date(value);
                if (Number.isNaN(date.getTime())) {
                    return value;
                }

                return new Intl.DateTimeFormat(document.documentElement.lang || 'nl', {
                    dateStyle: 'medium',
                    timeStyle: 'short',
                }).format(date);
            };

            const composeConceptText = (trigger) => {
                const form = trigger.closest('[data-linkedin-concept-form]');
                if (!form) {
                    return trigger.dataset.previewText || '';
                }

                const parts = [];
                const body = (form.querySelector('[name="body"]')?.value || '').trim();
                const sourceUrl = (form.querySelector('[name="source_url"]')?.value || '').trim();
                const hashtags = (form.querySelector('[name="hashtags"]')?.value || '').trim();

                if (body !== '') {
                    parts.push(body);
                }
                if (sourceUrl !== '') {
                    parts.push(sourceUrl);
                }
                if (hashtags !== '') {
                    parts.push(hashtags);
                }

                return parts.join('\n\n') || trigger.dataset.previewText || '';
            };

            const withDisclaimer = (text) => {
                text = (text || '').trim();
                if (!disclaimerEnabled || !disclaimerText) {
                    return text;
                }

                const normalize = (value) => value.replace(/\s+/g, ' ').trim().toLowerCase();
                if (normalize(text).includes(normalize(disclaimerText))) {
                    return text;
                }

                return [disclaimerText, text].filter(Boolean).join('\n\n');
            };

            const openPreview = (source) => {
                pendingForm = source.matches('form') ? source : null;
                const mode = source.dataset.previewMode || 'publish';
                const scheduleValue = source.querySelector?.('[name="scheduled_for"]')?.value || '';
                const account = selectedOptionText(source, 'social_account_id') || source.dataset.previewAccount || 'Social account';
                const scheduledLabel = formatSchedule(scheduleValue) || source.dataset.previewScheduled || 'Direct publish';
                const previewText = withDisclaimer(mode === 'concept' ? composeConceptText(source) : source.dataset.previewText);

                if (textNode) {
                    textNode.textContent = previewText || 'No preview text available.';
                }
                if (titleNode) {
                    titleNode.textContent = source.dataset.previewTitle || 'Social post';
                }
                if (accountNode) {
                    accountNode.textContent = account;
                }
                if (timeNode) {
                    timeNode.textContent = scheduledLabel;
                }
                if (actionNode) {
                    actionNode.textContent = mode === 'concept'
                        ? 'Concept preview'
                        : (mode === 'schedule' ? `Schedule for ${scheduledLabel}` : 'Publish now');
                }

                dialog.showModal();
                window.lucide?.createIcons();
            };

            document.querySelectorAll('[data-linkedin-preview-form]').forEach((form) => {
                form.addEventListener('submit', (event) => {
                    if (form.dataset.previewConfirmed === 'true') {
                        return;
                    }

                    event.preventDefault();
                    openPreview(form);
                });
            });

            document.querySelectorAll('[data-linkedin-preview-trigger]').forEach((button) => {
                button.addEventListener('click', () => {
                    openPreview(button);
                });
            });

            dialog.querySelectorAll('[data-linkedin-preview-close]').forEach((button) => {
                button.addEventListener('click', () => {
                    pendingForm = null;
                    dialog.close();
                });
            });

            confirmButton?.addEventListener('click', () => {
                if (!pendingForm) {
                    dialog.close();
                    return;
                }

                pendingForm.dataset.previewConfirmed = 'true';
                dialog.close();
                pendingForm.requestSubmit();
            });

            dialog.addEventListener('close', () => {
                if (pendingForm?.dataset.previewConfirmed !== 'true') {
                    pendingForm = null;
                }
            });
        })();
    </script>
@endsection
