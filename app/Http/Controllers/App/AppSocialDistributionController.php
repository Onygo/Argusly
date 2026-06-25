<?php

namespace App\Http\Controllers\App;

use App\Enums\DistributionChannelType;
use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostType;
use App\Enums\SocialPostVariantStatus;
use App\Enums\SocialPublicationStatus;
use App\Enums\SupportedLanguage;
use App\Actions\Social\GenerateLinkedInPostFromContent;
use App\Http\Controllers\Controller;
use App\Jobs\SocialDistribution\GenerateSocialPostVariantsJob;
use App\Jobs\SocialDistribution\PublishSocialPostJob;
use App\Models\Campaign;
use App\Models\CampaignContent;
use App\Models\Content;
use App\Models\DistributionChannel;
use App\Models\SocialAccount;
use App\Models\SocialPostVariant;
use App\Models\SocialPublication;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SocialDistribution\SocialDistributionAuditLogger;
use App\Services\SocialDistribution\SocialPlatformCapabilities;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AppSocialDistributionController extends Controller
{
    public function index(Request $request): View
    {
        $workspace = $this->resolveWorkspace($request);
        $scheduleTimezone = $this->scheduleTimezone($request);

        $accounts = SocialAccount::query()
            ->where('workspace_id', $workspace->id)
            ->with(['distributionChannel', 'user'])
            ->orderBy('platform')
            ->orderBy('display_name')
            ->get();

        $publishableAccounts = $accounts->filter(fn (SocialAccount $account): bool => $account->isSchedulable())->values();

        $workspaceUsers = User::query()
            ->where('organization_id', $workspace->organization_id)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $activePublicationStatuses = [
            SocialPublicationStatus::SCHEDULED->value,
            SocialPublicationStatus::QUEUED->value,
            SocialPublicationStatus::RATE_LIMITED->value,
            SocialPublicationStatus::PUBLISHING->value,
            SocialPublicationStatus::FAILED->value,
        ];

        $campaigns = Campaign::query()
            ->where('workspace_id', $workspace->id)
            ->withCount([
                'contents',
                'distributionPlans',
                'socialPostVariants',
                'socialPublications',
                'socialPublications as active_social_publications_count' => fn ($query) => $query->whereIn('status', $activePublicationStatuses),
                'socialPublications as published_social_publications_count' => fn ($query) => $query->where('status', SocialPublicationStatus::PUBLISHED->value),
            ])
            ->latest()
            ->limit(12)
            ->get();

        $variants = SocialPostVariant::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', '!=', SocialPostVariantStatus::PUBLISHED->value)
            ->with(['campaign', 'campaignContent', 'socialAccount', 'publications.socialAccount'])
            ->latest()
            ->limit(30)
            ->get();

        $publications = SocialPublication::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', $activePublicationStatuses)
            ->with(['campaign', 'socialAccount', 'metrics', 'variant.campaign'])
            ->orderByRaw("CASE status WHEN 'scheduled' THEN 0 WHEN 'queued' THEN 1 WHEN 'rate_limited' THEN 2 WHEN 'failed' THEN 3 ELSE 4 END")
            ->latest('scheduled_for')
            ->limit(30)
            ->get();

        $timelinePublications = SocialPublication::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', '!=', SocialPublicationStatus::CANCELED->value)
            ->with(['campaign', 'socialAccount', 'metrics', 'variant.campaign'])
            ->latest('published_at')
            ->latest('scheduled_for')
            ->limit(30)
            ->get();

        $contentItems = Content::query()
            ->where('workspace_id', $workspace->id)
            ->latest()
            ->limit(20)
            ->get([
                'id',
                'title',
                'workspace_id',
                'language',
                'seo_canonical',
                'published_url',
                'public_blog_excerpt',
                'seo_meta_description',
                'public_blog_tags',
                'primary_keyword',
                'aeo_breakdown',
            ]);

        $timeline = $timelinePublications
            ->filter(fn (SocialPublication $publication): bool => $publication->scheduled_for !== null || $publication->published_at !== null)
            ->sortBy(fn (SocialPublication $publication): int => ($publication->published_at ?? $publication->scheduled_for)?->timestamp ?? 0)
            ->groupBy(fn (SocialPublication $publication): string => ($publication->published_at ?? $publication->scheduled_for)?->copy()->timezone($scheduleTimezone)->format('Y-m-d') ?? 'Unscheduled');

        return view('app.social-distribution.index', [
            'workspace' => $workspace,
            'accounts' => $accounts,
            'publishableAccounts' => $publishableAccounts,
            'workspaceUsers' => $workspaceUsers,
            'campaigns' => $campaigns,
            'variants' => $variants,
            'publications' => $publications,
            'contentItems' => $contentItems,
            'contentDistributionContexts' => $contentItems->mapWithKeys(fn (Content $content): array => [
                (string) $content->id => $this->contentDistributionContext($content),
            ]),
            'timeline' => $timeline,
            'postTypes' => SocialPostType::values(),
            'scheduleTimezone' => $scheduleTimezone,
        ]);
    }

    public function createDraftFromContent(Request $request, GenerateLinkedInPostFromContent $action, SocialDistributionAuditLogger $audit): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request);
        $data = $request->validate([
            'content_id' => ['required', 'string', Rule::exists('contents', 'id')->where('workspace_id', $workspace->id)],
            'campaign_id' => ['nullable', 'string', Rule::exists('campaigns', 'id')->where('workspace_id', $workspace->id)],
            'social_account_id' => ['nullable', 'string', Rule::exists('social_accounts', 'id')->where('workspace_id', $workspace->id)],
            'language' => ['required', 'string', Rule::in(SupportedLanguage::values())],
            'source_url' => ['nullable', 'url', 'max:500'],
            'hashtags' => ['nullable', 'string', 'max:240'],
            'target_audience' => ['nullable', 'string', 'max:180'],
            'tone_of_voice' => ['nullable', 'string', 'max:180'],
            'utm_source' => ['nullable', 'string', 'max:120'],
            'utm_medium' => ['nullable', 'string', 'max:120'],
            'utm_campaign' => ['nullable', 'string', 'max:180'],
            'utm_content' => ['nullable', 'string', 'max:180'],
            'utm_term' => ['nullable', 'string', 'max:180'],
            'variant_count' => ['nullable', 'integer', 'min:3', 'max:5'],
            'desired_post_length' => ['nullable', 'string', Rule::in(['short', 'standard', 'long'])],
            'desired_publication_date' => ['nullable', 'date'],
        ]);

        $content = Content::query()->where('workspace_id', $workspace->id)->findOrFail($data['content_id']);
        $campaign = ! empty($data['campaign_id'])
            ? Campaign::query()->where('workspace_id', $workspace->id)->findOrFail($data['campaign_id'])
            : null;
        $socialAccount = ! empty($data['social_account_id'])
            ? SocialAccount::query()->where('workspace_id', $workspace->id)->findOrFail($data['social_account_id'])
            : null;
        $trackingParameters = $this->trackingParametersFromData($data);
        $this->updateCampaignTracking($campaign, $data);

        $post = $action->handle($content, [
            'campaign' => $campaign,
            'social_account' => $socialAccount,
            'language' => $data['language'],
            'source_url' => $data['source_url'] ?? null,
            'tracking_parameters' => $trackingParameters,
            'hashtags' => $this->parseHashtags($data['hashtags'] ?? ''),
            'target_audience' => $data['target_audience'] ?? null,
            'tone_of_voice' => $data['tone_of_voice'] ?? null,
            'variant_count' => $data['variant_count'] ?? 5,
            'desired_post_length' => $data['desired_post_length'] ?? 'standard',
            'desired_publication_date' => $data['desired_publication_date'] ?? null,
            'distribution_context' => $this->contentDistributionContext($content),
        ]);

        $audit->record($post, 'social_post.draft_created', null, $post->attributesToArray());

        return back()->with('status', 'LinkedIn draft created from content. Review and approve before scheduling.');
    }

    public function connectLinkedIn(Request $request, SocialDistributionAuditLogger $audit): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request);
        $data = $request->validate([
            'display_name' => ['nullable', 'string', 'max:180'],
            'account_type' => ['nullable', 'string', Rule::in(['person', 'organization', 'business', 'creator'])],
            'owner_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $workspace->organization_id)],
            'labels' => ['nullable', 'string', 'max:180'],
            'tone_profile' => ['nullable', 'string', 'max:500'],
            'engagement_role' => ['nullable', 'string', Rule::in(['primary_publisher', 'amplifier', 'commenter', 'reviewer', 'observer'])],
            'approval_policy' => ['nullable', 'string', Rule::in(['required', 'optional'])],
            'posting_limit_per_day' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $channel = DistributionChannel::query()->firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'name' => 'LinkedIn',
            ],
            [
                'organization_id' => $workspace->organization_id,
                'type' => DistributionChannelType::LINKEDIN->value,
                'provider' => SocialPlatform::LINKEDIN->value,
                'status' => DistributionChannel::STATUS_ACTIVE,
                'capabilities' => ['text_post', 'scheduled_publish', 'analytics_import'],
                'planning_rules' => ['requires_approval' => true],
                'metadata' => ['oauth_placeholder' => true],
            ]
        );

        $account = SocialAccount::query()->create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'distribution_channel_id' => $channel->id,
            'user_id' => $data['owner_user_id'] ?? $request->user()->id,
            'provider' => SocialPlatform::LINKEDIN->value,
            'platform' => SocialPlatform::LINKEDIN->value,
            'account_type' => $data['account_type'] ?? 'person',
            'display_name' => filled($data['display_name'] ?? null) ? $data['display_name'] : 'LinkedIn account',
            'status' => SocialAccountStatus::OAUTH_PENDING->value,
            'scopes' => ['w_member_social', 'r_organization_social'],
            'oauth' => [
                'provider' => 'linkedin',
                'status' => 'placeholder',
                'authorization_url' => null,
                'scopes' => ['w_member_social', 'r_organization_social'],
            ],
            'profile' => [
                'labels' => $this->parseLabels($data['labels'] ?? ''),
                'tone_profile' => $data['tone_profile'] ?? null,
                'engagement_role' => $data['engagement_role'] ?? 'primary_publisher',
            ],
            'publishing_rules' => [
                'approval_required' => ($data['approval_policy'] ?? 'required') === 'required',
                'approval_policy' => $data['approval_policy'] ?? 'required',
                'permissions' => ['draft', 'schedule', 'publish'],
            ],
            'rate_limit_policy' => [
                'bucket' => 'publish',
                'retry_strategy' => 'exponential_backoff',
                'posting_limit_per_day' => $data['posting_limit_per_day'] ?? null,
            ],
        ]);

        $audit->record($account, 'account.oauth_placeholder_created', null, $account->attributesToArray());

        return back()->with('status', 'LinkedIn account placeholder created. Configure OAuth credentials before publishing.');
    }

    public function updateAccount(Request $request, SocialAccount $account, SocialDistributionAuditLogger $audit): RedirectResponse
    {
        $this->authorizeAccount($request, $account);
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:180'],
            'account_type' => ['nullable', 'string', Rule::in(['person', 'organization'])],
            'owner_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $account->organization_id)],
            'labels' => ['nullable', 'string', 'max:180'],
            'tone_profile' => ['nullable', 'string', 'max:500'],
            'engagement_role' => ['nullable', 'string', Rule::in(['primary_publisher', 'amplifier', 'commenter', 'reviewer', 'observer'])],
            'approval_policy' => ['nullable', 'string', Rule::in(['required', 'optional'])],
            'can_publish' => ['nullable', 'boolean'],
            'can_schedule' => ['nullable', 'boolean'],
            'posting_limit_per_day' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $before = $account->attributesToArray();
        $profile = array_replace((array) $account->profile, [
            'labels' => $this->parseLabels($data['labels'] ?? ''),
            'tone_profile' => $data['tone_profile'] ?? null,
            'engagement_role' => $data['engagement_role'] ?? $account->engagementRole() ?? 'primary_publisher',
        ]);
        $existingPermissions = (array) data_get($account->publishing_rules, 'permissions', ['draft', 'schedule', 'publish']);
        $updatesPermissions = $request->hasAny(['can_schedule', 'can_publish', 'account_type', 'labels', 'tone_profile', 'engagement_role', 'approval_policy', 'posting_limit_per_day']);
        $permissions = ['draft'];
        if ($updatesPermissions ? $request->boolean('can_schedule') : in_array('schedule', $existingPermissions, true)) {
            $permissions[] = 'schedule';
        }
        if ($updatesPermissions ? $request->boolean('can_publish') : in_array('publish', $existingPermissions, true)) {
            $permissions[] = 'publish';
        }
        $approvalPolicy = $data['approval_policy']
            ?? (string) data_get($account->publishing_rules, 'approval_policy', data_get($account->publishing_rules, 'approval_required', true) ? 'required' : 'optional');

        $account->forceFill([
            'display_name' => $data['display_name'],
            'account_type' => $data['account_type'] ?? $account->account_type,
            'user_id' => $data['owner_user_id'] ?? null,
            'profile' => $profile,
            'publishing_rules' => array_replace((array) $account->publishing_rules, [
                'approval_required' => $approvalPolicy === 'required',
                'approval_policy' => $approvalPolicy,
                'permissions' => $permissions,
            ]),
            'rate_limit_policy' => array_replace((array) $account->rate_limit_policy, [
                'posting_limit_per_day' => isset($data['posting_limit_per_day']) ? (int) $data['posting_limit_per_day'] : null,
            ]),
        ])->save();

        $audit->record($account, 'account.updated', $before, $account->attributesToArray());

        return back()->with('status', 'LinkedIn account name updated.');
    }

    public function destroyAccount(Request $request, SocialAccount $account, SocialDistributionAuditLogger $audit): RedirectResponse
    {
        $this->authorizeAccount($request, $account);
        $before = $account->attributesToArray();

        SocialPostVariant::query()
            ->where('social_account_id', $account->id)
            ->update(['social_account_id' => null]);

        $account->delete();
        $audit->record($account, 'account.removed', $before, null, [
            'removed_by' => $request->user()->id,
        ]);

        return back()->with('status', 'LinkedIn account removed.');
    }

    public function requestVariants(Request $request, SocialDistributionAuditLogger $audit): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request);
        $data = $request->validate([
            'campaign_id' => ['required', 'string', Rule::exists('campaigns', 'id')->where('workspace_id', $workspace->id)],
            'campaign_content_id' => ['nullable', 'string', Rule::exists('campaign_contents', 'id')],
            'social_account_id' => ['nullable', 'string', Rule::exists('social_accounts', 'id')->where('workspace_id', $workspace->id)],
            'platform' => ['nullable', 'string', Rule::in([SocialPlatform::LINKEDIN->value, SocialPlatform::INSTAGRAM->value])],
            'post_type' => ['required', 'string', Rule::in(SocialPostType::values())],
            'variant_count' => ['required', 'integer', 'min:1', 'max:5'],
            'language' => ['required', 'string', Rule::in(SupportedLanguage::values())],
            'source_url' => ['nullable', 'url', 'max:500'],
            'media_url' => ['nullable', 'url', 'max:1000'],
            'hashtags' => ['nullable', 'string', 'max:240'],
            'utm_source' => ['nullable', 'string', 'max:120'],
            'utm_medium' => ['nullable', 'string', 'max:120'],
            'utm_campaign' => ['nullable', 'string', 'max:180'],
            'utm_content' => ['nullable', 'string', 'max:180'],
            'utm_term' => ['nullable', 'string', 'max:180'],
        ]);

        $campaign = Campaign::query()->where('workspace_id', $workspace->id)->findOrFail($data['campaign_id']);
        $platform = $data['platform'] ?? SocialPlatform::LINKEDIN->value;
        $trackingParameters = $this->trackingParametersFromData($data);
        $this->updateCampaignTracking($campaign, $data);
        $targetAccount = ! empty($data['social_account_id'])
            ? SocialAccount::query()->where('workspace_id', $workspace->id)->findOrFail($data['social_account_id'])
            : null;
        if ($targetAccount && ($targetAccount->platform?->value ?? (string) $targetAccount->platform) !== $platform) {
            return back()->withErrors(['social_account_id' => 'Choose an account for the selected social channel.']);
        }
        $campaignContent = null;
        if (! empty($data['campaign_content_id'])) {
            $campaignContent = CampaignContent::query()
                ->where('campaign_id', $campaign->id)
                ->findOrFail($data['campaign_content_id']);
        }

        $variantIds = [];
        for ($i = 1; $i <= (int) $data['variant_count']; $i++) {
            $variant = SocialPostVariant::query()->create([
                'organization_id' => $workspace->organization_id,
                'workspace_id' => $workspace->id,
                'campaign_id' => $campaign->id,
                'campaign_content_id' => $campaignContent?->id,
                'content_id' => $campaignContent?->content_id ?: $campaignContent?->source_content_id,
                'social_account_id' => $data['social_account_id'] ?? null,
                'platform' => $platform,
                'post_type' => $data['post_type'],
                'status' => SocialPostVariantStatus::GENERATION_REQUESTED->value,
                'variant_number' => $i,
                'media_refs' => $this->mediaRefsFromUrl($data['media_url'] ?? null),
                'generation_prompt_context' => [
                    'campaign_id' => (string) $campaign->id,
                    'campaign_content_id' => $campaignContent?->id,
                    'platform' => $platform,
                    'objective' => $campaign->objective,
                    'asset_type' => $campaignContent?->asset_type?->value ?? $campaignContent?->asset_type,
                    'internal_linking_strategy' => $campaign->internal_linking_strategy,
                    'language' => $data['language'],
                    'source_url' => $data['source_url'] ?? null,
                    'media_required' => app(SocialPlatformCapabilities::class)->requiresMedia($platform),
                    'tracking_parameters' => $trackingParameters,
                    'hashtags' => $this->parseHashtags($data['hashtags'] ?? ''),
                    'target_social_account' => $targetAccount ? [
                        'display_name' => $targetAccount->display_name,
                        'account_type' => $targetAccount->account_type,
                        'labels' => $targetAccount->labels(),
                        'tone_profile' => $targetAccount->toneProfile(),
                        'engagement_role' => $targetAccount->engagementRole(),
                    ] : null,
                ],
            ]);
            $variantIds[] = (string) $variant->id;
            $audit->record($variant, 'variant.generation_requested', null, $variant->attributesToArray());
        }

        GenerateSocialPostVariantsJob::dispatch($variantIds)->afterCommit();

        $statusLabel = $platform === SocialPlatform::LINKEDIN->value
            ? 'LinkedIn post'
            : app(SocialPlatformCapabilities::class)->postLabel($platform);

        return back()->with('status', $statusLabel.' variant generation queued.');
    }

    public function approveVariant(Request $request, SocialPostVariant $variant, SocialDistributionAuditLogger $audit): RedirectResponse
    {
        $this->authorizeVariant($request, $variant);
        if (! $this->variantHasCopy($variant)) {
            return back()->withErrors(['variant' => 'Generate or add post copy before approving this LinkedIn variant.']);
        }

        $before = $variant->attributesToArray();

        $variant->forceFill([
            'status' => SocialPostVariantStatus::APPROVED->value,
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
            'approval_notes' => $request->input('approval_notes'),
        ])->save();

        if ($variant->socialPost) {
            $variant->socialPost->forceFill([
                'status' => 'approved',
                'body' => $variant->publishingText(),
                'error_message' => null,
            ])->save();
        }

        $audit->record($variant, 'variant.approved', $before, $variant->attributesToArray());

        return back()->with('status', 'Social post variant approved.');
    }

    public function unapproveVariant(Request $request, SocialPostVariant $variant, SocialDistributionAuditLogger $audit): RedirectResponse
    {
        $this->authorizeVariant($request, $variant);
        abort_unless(in_array((string) $request->user()->role, ['owner', 'admin', 'editor', 'superadmin'], true), 403);

        $status = (string) ($variant->status?->value ?? $variant->status);
        if (! in_array($status, [SocialPostVariantStatus::APPROVED->value, SocialPostVariantStatus::SCHEDULED->value], true)) {
            return back()->withErrors(['variant' => 'Only approved LinkedIn variants can be moved back to draft.']);
        }

        if ($variant->publications()->whereIn('status', [
            SocialPublicationStatus::SCHEDULED->value,
            SocialPublicationStatus::QUEUED->value,
            SocialPublicationStatus::PUBLISHING->value,
            SocialPublicationStatus::PUBLISHED->value,
        ])->exists()) {
            return back()->withErrors(['variant' => 'This LinkedIn variant already has an active publication and cannot be unapproved.']);
        }

        $before = $variant->attributesToArray();
        $variant->forceFill([
            'status' => SocialPostVariantStatus::DRAFT->value,
            'approved_at' => null,
            'approved_by' => null,
            'approval_notes' => null,
        ])->save();

        $audit->record($variant, 'variant.approval_revoked', $before, $variant->attributesToArray());

        return back()->with('status', 'LinkedIn variant moved back to draft.');
    }

    public function destroyVariant(Request $request, SocialPostVariant $variant, SocialDistributionAuditLogger $audit): RedirectResponse
    {
        $this->authorizeVariant($request, $variant);
        abort_unless(in_array((string) $request->user()->role, ['owner', 'admin', 'editor', 'superadmin'], true), 403);

        if ($variant->publications()->exists()) {
            return back()->withErrors(['variant' => 'This LinkedIn variant has publication history and cannot be deleted.']);
        }

        $before = $variant->attributesToArray();
        $variant->delete();

        $audit->record($variant, 'variant.removed', $before, null, [
            'removed_by' => $request->user()->id,
        ]);

        return back()->with('status', 'LinkedIn variant removed.');
    }

    public function updateVariant(Request $request, SocialPostVariant $variant, SocialDistributionAuditLogger $audit): RedirectResponse
    {
        $this->authorizeVariant($request, $variant);
        abort_unless(in_array((string) $request->user()->role, ['owner', 'admin', 'editor', 'superadmin'], true), 403);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:3000'],
            'language' => ['required', 'string', Rule::in(SupportedLanguage::values())],
            'source_url' => ['nullable', 'url', 'max:500'],
            'media_url' => ['nullable', 'url', 'max:1000'],
            'hashtags' => ['nullable', 'string', 'max:240'],
            'selected' => ['nullable', 'boolean'],
        ]);

        $before = $variant->attributesToArray();
        $context = array_replace((array) $variant->generation_prompt_context, [
            'language' => $data['language'],
            'source_url' => $data['source_url'] ?? null,
            'media_required' => $variant->requiresMedia(),
            'hashtags' => $this->parseHashtags($data['hashtags'] ?? ''),
        ]);

        $variant->forceFill([
            'body' => $variant->forceFill(['body' => $data['body']])->bodyWithoutRepeatedHook(),
            'hashtags' => $this->parseHashtags($data['hashtags'] ?? ''),
            'media_refs' => $this->mediaRefsFromUrl($data['media_url'] ?? null, (array) $variant->media_refs),
            'generation_prompt_context' => $context,
            'selected' => $request->boolean('selected'),
            'status' => SocialPostVariantStatus::DRAFT->value,
        ])->save();

        if ($variant->social_post_id && $request->boolean('selected')) {
            SocialPostVariant::query()
                ->where('social_post_id', $variant->social_post_id)
                ->whereKeyNot($variant->id)
                ->update(['selected' => false]);

            $variant->socialPost?->forceFill(['body' => $variant->publishingText()])->save();
        }

        $audit->record($variant, 'variant.updated', $before, $variant->attributesToArray());

        return back()->with('status', 'LinkedIn variant updated.');
    }

    public function schedule(Request $request, SocialPostVariant $variant, SocialDistributionAuditLogger $audit): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request);
        $this->authorizeVariant($request, $variant);
        if (($variant->status?->value ?? $variant->status) !== SocialPostVariantStatus::APPROVED->value) {
            return back()->withErrors(['variant' => 'Approve this LinkedIn variant before scheduling distribution.']);
        }

        if (! $this->variantHasCopy($variant)) {
            return back()->withErrors(['variant' => 'Generate or add post copy before scheduling this LinkedIn variant.']);
        }

        $data = $request->validate([
            'social_account_id' => [
                'required',
                'string',
                Rule::exists('social_accounts', 'id')
                    ->where('workspace_id', $workspace->id)
                    ->where('platform', $variant->platform?->value ?? (string) $variant->platform)
                    ->whereIn('status', [SocialAccountStatus::CONNECTED->value, SocialAccountStatus::ACTIVE->value]),
            ],
            'scheduled_for' => ['required', 'date'],
            'timezone' => ['nullable', 'timezone'],
        ]);

        $socialAccount = SocialAccount::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($data['social_account_id']);

        if (! $socialAccount->isSchedulable()) {
            return back()->withErrors(['social_account_id' => 'Choose a connected '.$variant->platformLabel().' account with publish permission before scheduling.']);
        }

        if ($reason = $variant->publishingBlockedReason()) {
            return back()->withErrors(['variant' => $reason]);
        }

        $scheduledFor = CarbonImmutable::parse(
            (string) $data['scheduled_for'],
            (string) ($data['timezone'] ?? $this->scheduleTimezone($request)),
        )->utc();

        $publication = SocialPublication::query()->create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'social_account_id' => $data['social_account_id'],
            'social_post_variant_id' => $variant->id,
            'campaign_id' => $variant->campaign_id,
            'campaign_distribution_plan_id' => $variant->campaign_distribution_plan_id,
            'platform' => $variant->platform?->value ?? $variant->platform,
            'status' => SocialPublicationStatus::SCHEDULED->value,
            'scheduled_for' => $scheduledFor,
            'payload_snapshot' => [
                'hook' => $variant->hook,
                'body' => $variant->bodyWithoutRepeatedHook(),
                'publishing_text' => $variant->publishingText(),
                'language' => $variant->languageCode(),
                'source_url' => $variant->sourceUrl(),
                'hashtags' => $variant->hashtags,
                'mentions' => $variant->mentions,
                'media_refs' => $variant->media_refs,
            ],
            'metadata' => [
                'scheduled_timezone' => (string) ($data['timezone'] ?? $this->scheduleTimezone($request)),
                'scheduled_local' => (string) $data['scheduled_for'],
            ],
        ]);

        $variant->forceFill(['status' => SocialPostVariantStatus::SCHEDULED->value])->save();
        $audit->record($publication, 'publication.scheduled', null, $publication->attributesToArray());

        return back()->with('status', 'Social post scheduled.');
    }

    public function queuePublication(Request $request, SocialPublication $publication, SocialDistributionAuditLogger $audit): RedirectResponse
    {
        $this->authorizePublication($request, $publication);
        $before = $publication->attributesToArray();

        $publication->forceFill([
            'status' => SocialPublicationStatus::QUEUED->value,
            'queued_at' => now(),
        ])->save();

        $audit->record($publication, 'publication.queued', $before, $publication->attributesToArray());
        PublishSocialPostJob::dispatch((string) $publication->id)->afterCommit();

        return back()->with('status', 'Social publication queued.');
    }

    private function resolveWorkspace(Request $request): Workspace
    {
        return Workspace::query()
            ->where('organization_id', $request->user()->organization_id)
            ->when($request->query('workspace_id'), fn ($query, $id) => $query->where('id', $id))
            ->orderBy('created_at')
            ->firstOrFail();
    }

    /**
     * @return array<string,mixed>
     */
    private function contentDistributionContext(Content $content): array
    {
        $keywords = collect([
            $content->primary_keyword,
            ...((array) $content->public_blog_tags),
        ])
            ->map(fn (mixed $keyword): string => Str::of((string) $keyword)->stripTags()->squish()->toString())
            ->filter()
            ->unique(fn (string $keyword): string => Str::lower($keyword))
            ->take(8)
            ->values();

        $summary = Str::of((string) ($content->public_blog_excerpt ?: $content->seo_meta_description ?: $content->title))
            ->stripTags()
            ->squish()
            ->limit(260, '')
            ->toString();

        $titleText = Str::lower($content->title.' '.$content->primary_keyword.' '.$keywords->implode(' '));
        $isAeo = Str::contains($titleText, ['aeo', 'answer engine', 'ai visibility', 'ai zichtbaarheid', 'visibility answer']);

        $defaultHashtags = $isAeo
            ? ['#AIVisibility', '#AEO', '#ContentMarketing', '#B2B']
            : $keywords
                ->map(fn (string $keyword): string => '#'.Str::of($keyword)->replaceMatches('/[^A-Za-z0-9]+/', '')->limit(32, '')->toString())
                ->filter(fn (string $tag): bool => strlen($tag) > 1)
                ->take(4)
                ->values()
                ->all();

        return [
            'subject' => $isAeo ? 'Visibility Answer Engine Optimization (AEO)' : $content->title,
            'goal' => $isAeo ? 'Thought leadership opbouwen en verkeer naar het artikel genereren.' : 'Thought leadership opbouwen en verkeer naar het artikel genereren.',
            'primary_cta' => 'Lees het volledige artikel op Argusly.',
            'target_audience' => $isAeo ? 'B2B marketing leaders, content teams en growth teams' : 'B2B marketing leaders',
            'tone_of_voice' => 'Strategisch, praktisch en direct',
            'language' => $content->localeCode(),
            'canonical_url' => $content->seo_canonical ?: $content->published_url,
            'summary' => $summary,
            'seo_keywords' => $keywords->all(),
            'ai_keywords' => $isAeo
                ? ['AI zoekmachines', 'antwoordwaardige content', 'ChatGPT', 'Gemini', 'Perplexity', 'AI zichtbaarheid']
                : $keywords->all(),
            'recommended_hashtags' => $defaultHashtags !== [] ? $defaultHashtags : ['#ContentMarketing', '#B2B'],
            'utm_parameters' => [
                'utm_source' => 'linkedin',
                'utm_medium' => 'social',
                'utm_campaign' => Str::slug((string) ($content->primary_keyword ?: $content->title)) ?: 'article-distribution',
                'utm_content' => 'article-variant',
            ],
            'desired_post_length' => 'standard',
            'variant_count' => 5,
            'account_type' => 'company',
            'key_messages' => $isAeo ? [
                'AI zoekmachines veranderen hoe bedrijven gevonden worden.',
                'SEO alleen is niet meer voldoende.',
                'AEO draait om antwoordwaardige content.',
                'Content moet begrijpelijk zijn voor zowel mensen als AI.',
                'Organisaties die nu investeren bouwen een voorsprong op.',
                'Argusly helpt bedrijven hun AI zichtbaarheid te analyseren, kansen te ontdekken en content autonoom te organiseren.',
            ] : [
                $summary,
                'Maak het inzicht praktisch genoeg om op te volgen.',
                'Verbind de post duidelijk met het volledige artikel.',
            ],
            'desired_structure' => ['Hook', 'Probleem', 'Inzicht', 'Praktisch advies', 'CTA naar artikel'],
            'variant_angles' => $isAeo ? [
                'Thought leadership: waarom traditionele SEO niet meer voldoende is.',
                'Praktische tip: 3 manieren om vandaag beter zichtbaar te worden in AI.',
                'Trend: hoe ChatGPT, Gemini en Perplexity de customer journey veranderen.',
                'Vraag/discussie: wordt jouw bedrijf al genoemd door AI?',
                'Data driven: waarom bedrijven minder klikken uit Google krijgen maar zichtbaar moeten blijven.',
            ] : [
                'Thought leadership',
                'Praktische tip',
                'Trend',
                'Vraag/discussie',
                'Data driven',
            ],
        ];
    }

    private function authorizeVariant(Request $request, SocialPostVariant $variant): void
    {
        abort_unless((int) $variant->organization_id === (int) $request->user()->organization_id, 404);
    }

    private function authorizeAccount(Request $request, SocialAccount $account): void
    {
        abort_unless((int) $account->organization_id === (int) $request->user()->organization_id, 404);
    }

    private function authorizePublication(Request $request, SocialPublication $publication): void
    {
        abort_unless((int) $publication->organization_id === (int) $request->user()->organization_id, 404);
    }

    private function variantHasCopy(SocialPostVariant $variant): bool
    {
        return trim($variant->bodyWithoutRepeatedHook()) !== '';
    }

    private function scheduleTimezone(Request $request): string
    {
        $timezone = trim((string) $request->input('timezone', ''));
        if ($timezone !== '' && in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            return $timezone;
        }

        return 'Europe/Amsterdam';
    }

    /**
     * @return list<string>
     */
    private function parseLabels(?string $value): array
    {
        return collect(preg_split('/[,]+/', (string) $value) ?: [])
            ->map(fn (string $label): string => trim($label))
            ->filter()
            ->unique()
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function parseHashtags(?string $value): array
    {
        return collect(preg_split('/[\s,]+/', (string) $value) ?: [])
            ->map(fn (string $tag): string => trim($tag))
            ->filter()
            ->map(fn (string $tag): string => preg_replace('/[^A-Za-z0-9_#]/', '', $tag) ?: '')
            ->filter()
            ->map(fn (string $tag): string => str_starts_with($tag, '#') ? $tag : '#'.$tag)
            ->unique()
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * @param array<int,mixed> $existing
     * @return array<int,array<string,string>>
     */
    private function mediaRefsFromUrl(?string $url, array $existing = []): array
    {
        $url = trim((string) $url);

        if ($url === '') {
            return $existing;
        }

        return [[
            'type' => 'image',
            'url' => $url,
            'source' => 'manual_url',
        ]];
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function updateCampaignTracking(?Campaign $campaign, array $data): void
    {
        if (! $campaign) {
            return;
        }

        $parameters = $this->trackingParametersFromData($data);

        if ($parameters === []) {
            return;
        }

        $campaign->forceFill([
            'metadata' => array_replace((array) $campaign->metadata, [
                'tracking_parameters' => $parameters,
            ]),
        ])->save();
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,string>
     */
    private function trackingParametersFromData(array $data): array
    {
        return collect(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'])
            ->mapWithKeys(fn (string $key): array => [$key => trim((string) ($data[$key] ?? ''))])
            ->filter()
            ->all();
    }
}
