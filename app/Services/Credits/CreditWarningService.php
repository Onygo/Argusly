<?php

namespace App\Services\Credits;

use App\Models\ClientSite;
use App\Models\ContentAutomation;
use App\Models\Notification;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\LowCreditWarningNotification;
use App\Services\CreditWalletService;
use App\Services\Notifications\NotificationService;
use App\Services\ContentAutomation\AutomationLocaleResolver;
use Carbon\CarbonInterface;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;

class CreditWarningService
{
    public function __construct(
        private readonly CreditWalletService $creditWallets,
        private readonly WorkspaceCreditLedgerService $workspaceCredits,
        private readonly NotificationService $notifications,
        private readonly AutomationLocaleResolver $localeResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function evaluateWorkspace(Workspace $workspace): array
    {
        $workspace->loadMissing(['organization.primaryUser', 'organization.users', 'contentAutomations.clientSite']);

        $summary = $this->workspaceCredits->summary((string) $workspace->id);
        $siteAvailabilities = ClientSite::query()
            ->where('workspace_id', (string) $workspace->id)
            ->orderBy('created_at')
            ->get(['id', 'name', 'workspace_id'])
            ->map(function (ClientSite $site): array {
                return [
                    'site_id' => (string) $site->id,
                    'site_name' => (string) $site->name,
                    'available' => $this->creditWallets->getAvailableForClientSite((string) $site->id),
                ];
            });

        $workspaceAvailable = max(0, (int) ($summary['unallocated_credits'] ?? 0));
        $siteAvailable = (int) $siteAvailabilities->sum('available');
        $availableCredits = $workspaceAvailable + $siteAvailable;
        $usedCredits = max(0, (int) ($summary['used_credits'] ?? 0));
        $percentageBase = max(1, $availableCredits + $usedCredits);
        $remainingPercentage = round(($availableCredits / $percentageBase) * 100, 2);

        $absoluteThreshold = $this->absoluteThreshold();
        $percentageThreshold = $this->percentageThreshold();
        $minimumAutomationCredits = $this->minimumAutomationCredits();

        /** @var Collection<int, ContentAutomation> $activeAutomations */
        $activeAutomations = $workspace->contentAutomations
            ->filter(fn (ContentAutomation $automation): bool => $automation->isActive())
            ->values();

        $nextAutomationRunAt = $activeAutomations
            ->pluck('next_run_at')
            ->filter()
            ->sort()
            ->first();

        $riskyAutomations = $activeAutomations
            ->filter(function (ContentAutomation $automation) use ($minimumAutomationCredits): bool {
                if (! $automation->client_site_id) {
                    return false;
                }

                return $this->creditWallets->getAvailableForClientSite((string) $automation->client_site_id) < $minimumAutomationCredits;
            })
            ->values();

        $riskSiteIds = $riskyAutomations
            ->pluck('client_site_id')
            ->filter()
            ->unique()
            ->values();

        $riskSites = $siteAvailabilities
            ->filter(fn (array $site): bool => $riskSiteIds->contains($site['site_id']))
            ->values();

        $isLowAbsolute = $availableCredits <= $absoluteThreshold;
        $isLowPercentage = $remainingPercentage <= $percentageThreshold;
        $hasActiveAutomations = $activeAutomations->isNotEmpty();
        $isAutomationRisk = $hasActiveAutomations && ($isLowAbsolute || $isLowPercentage || $riskyAutomations->isNotEmpty());
        $isLow = $isLowAbsolute || $isLowPercentage || $riskyAutomations->isNotEmpty();
        $isBlocking = $riskyAutomations->isNotEmpty();
        $stateKey = $isLow
            ? $this->stateKey($availableCredits, $isAutomationRisk, $isBlocking, $absoluteThreshold, $percentageThreshold, $remainingPercentage)
            : null;
        $ctaUrl = route('app.billing.index') . '#buy-credit-packs';

        return [
            'workspace_id' => (string) $workspace->id,
            'workspace_name' => (string) $workspace->display_name,
            'organization_id' => (int) $workspace->organization_id,
            'available_credits' => $availableCredits,
            'workspace_available_credits' => $workspaceAvailable,
            'site_available_credits' => $siteAvailable,
            'remaining_percentage' => $remainingPercentage,
            'absolute_threshold' => $absoluteThreshold,
            'percentage_threshold' => $percentageThreshold,
            'minimum_automation_credits' => $minimumAutomationCredits,
            'is_low' => $isLow,
            'is_low_absolute' => $isLowAbsolute,
            'is_low_percentage' => $isLowPercentage,
            'is_automation_risk' => $isAutomationRisk,
            'is_blocking' => $isBlocking,
            'has_active_automations' => $hasActiveAutomations,
            'active_automation_count' => $activeAutomations->count(),
            'risky_automation_count' => $riskyAutomations->count(),
            'risk_sites' => $riskSites->all(),
            'next_automation_run_at' => $nextAutomationRunAt,
            'next_automation_run_label' => $nextAutomationRunAt instanceof CarbonInterface
                ? $nextAutomationRunAt->diffForHumans()
                : null,
            'state_key' => $stateKey,
            'cta_url' => $ctaUrl,
            'cta_label' => __('app.credits.low_warning.cta'),
            'title' => __('app.credits.low_warning.title'),
            'body' => $this->bodyForEvaluation(
                hasActiveAutomations: $hasActiveAutomations,
                automationCount: $activeAutomations->count(),
                nextRunLabel: $nextAutomationRunAt instanceof CarbonInterface ? $nextAutomationRunAt->diffForHumans() : null,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function evaluateAutomation(ContentAutomation $automation): array
    {
        $automation->loadMissing(['workspace.organization', 'clientSite']);

        $workspaceEvaluation = $this->evaluateWorkspace($automation->workspace);
        $estimate = $this->estimateAutomationCredits($automation);
        $requiredCredits = (int) ($estimate['required_credits'] ?? 0);
        $availableCredits = $automation->client_site_id
            ? $this->creditWallets->getAvailableForClientSite((string) $automation->client_site_id)
            : (int) ($workspaceEvaluation['available_credits'] ?? 0);
        $canRun = $availableCredits >= $requiredCredits;
        $message = $canRun
            ? null
            : sprintf(
                'This automation needs %d credits, but only %d are available.',
                $requiredCredits,
                $availableCredits,
            );

        return [
            'can_run' => $canRun,
            'available_credits' => $availableCredits,
            'required_credits' => $requiredCredits,
            'estimate' => $estimate,
            'workspace_evaluation' => $workspaceEvaluation,
            'skip_reason' => $canRun ? null : 'insufficient_credits',
            'message' => $message,
            'user_safe_message' => $canRun
                ? null
                : sprintf(
                    'This automation could not continue because there are not enough credits available. Required: %d, available: %d. Please add credits or reduce the automation scope and try again.',
                    $requiredCredits,
                    $availableCredits,
                ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function estimateAutomationCredits(ContentAutomation $automation): array
    {
        $chainSize = max(1, (int) ($automation->chain_size ?: 1));
        $configuredLocales = $this->localeResolver->configuredLocales($automation);
        $sourceLocale = $this->localeResolver->sourceLocale($automation);
        $translationLocales = $this->localeResolver->shouldTranslate($automation)
            ? $this->localeResolver->targetLocales($automation)
            : [];
        $sourceGenerationCreditsPerItem = max(1, (int) config('publishlayer.ai.drafts.credit_cost', 4));
        $translationCreditsPerLocale = max(1, (int) config('translation.default_credit_cost', 6));
        $sourceGenerationCredits = $chainSize * $sourceGenerationCreditsPerItem;
        $translationCredits = $chainSize * count($translationLocales) * $translationCreditsPerLocale;

        return [
            'chain_size' => $chainSize,
            'source_locale' => $sourceLocale,
            'configured_locales' => $configuredLocales,
            'translation_locales' => $translationLocales,
            'source_generation_credits_per_item' => $sourceGenerationCreditsPerItem,
            'translation_credits_per_locale' => $translationCreditsPerLocale,
            'source_generation_credits' => $sourceGenerationCredits,
            'translation_credits' => $translationCredits,
            'required_credits' => $sourceGenerationCredits + $translationCredits,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function syncWorkspaceWarning(Workspace $workspace, bool $sendNotifications = true): array
    {
        $workspace->refresh();

        $evaluation = $this->evaluateWorkspace($workspace);
        $workspace->refresh();

        if (! $this->warningsEnabled()) {
            return array_merge($evaluation, [
                'warning_sent' => false,
                'notification_created' => false,
                'reset' => false,
            ]);
        }

        if (! (bool) ($evaluation['is_low'] ?? false)) {
            $reset = $this->resetWorkspaceWarningState($workspace);

            return array_merge($evaluation, [
                'warning_sent' => false,
                'notification_created' => false,
                'reset' => $reset,
            ]);
        }

        Log::info('credits.low_threshold_reached', [
            'workspace_id' => (string) $workspace->id,
            'state_key' => (string) ($evaluation['state_key'] ?? ''),
            'available_credits' => (int) ($evaluation['available_credits'] ?? 0),
            'active_automations' => (int) ($evaluation['active_automation_count'] ?? 0),
        ]);

        $notificationCreated = false;
        $warningSent = false;

        if ($sendNotifications) {
            $notification = $this->resolveOrCreateWorkspaceWarningNotification($workspace, $evaluation);

            $notificationCreated = $notification->wasRecentlyCreated;

            if ($notificationCreated) {
                Log::info('credits.low_warning_in_app_activated', [
                    'workspace_id' => (string) $workspace->id,
                    'notification_id' => (string) $notification->id,
                ]);
            }

            if ($this->shouldSendEmail($workspace, $evaluation)) {
                $warningSent = $this->sendWarningEmail($workspace, $evaluation);
            }
        }

        $workspace->forceFill([
            'low_credit_warning_state' => (string) ($evaluation['state_key'] ?? ''),
            'low_credit_warning_last_available' => (int) ($evaluation['available_credits'] ?? 0),
            'low_credit_warning_sent_at' => $warningSent
                ? now()
                : $workspace->low_credit_warning_sent_at,
        ])->save();

        return array_merge($evaluation, [
            'warning_sent' => $warningSent,
            'notification_created' => $notificationCreated,
            'reset' => false,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function mostUrgentForOrganization(int $organizationId): ?array
    {
        $evaluations = Workspace::query()
            ->where('organization_id', $organizationId)
            ->orderBy('created_at')
            ->get()
            ->map(fn (Workspace $workspace): array => $this->evaluateWorkspace($workspace))
            ->filter(fn (array $evaluation): bool => (bool) ($evaluation['is_low'] ?? false))
            ->values();

        if ($evaluations->isEmpty()) {
            return null;
        }

        return $evaluations->sortBy([
            fn (array $evaluation): int => (bool) ($evaluation['is_blocking'] ?? false) ? 0 : 1,
            fn (array $evaluation): int => (bool) ($evaluation['is_automation_risk'] ?? false) ? 0 : 1,
            fn (array $evaluation): int => (int) ($evaluation['available_credits'] ?? 0),
        ])->first();
    }

    public function warningsEnabled(): bool
    {
        return (bool) config('credits.warnings.enabled', true);
    }

    public function minimumAutomationCredits(): int
    {
        return max(1, (int) config('credits.warnings.minimum_automation_run_credits', 10));
    }

    private function absoluteThreshold(): int
    {
        return max(1, (int) config('credits.warnings.absolute_threshold', 10));
    }

    private function percentageThreshold(): float
    {
        return max(0.0, (float) config('credits.warnings.percentage_threshold', 15));
    }

    /**
     * @param  array<string, mixed>  $evaluation
     */
    private function shouldSendEmail(Workspace $workspace, array $evaluation): bool
    {
        $stateKey = (string) ($evaluation['state_key'] ?? '');
        if ($stateKey === '') {
            return false;
        }

        $sentAt = $workspace->low_credit_warning_sent_at;
        if (! $sentAt) {
            return true;
        }

        if ((string) ($workspace->low_credit_warning_state ?? '') !== $stateKey) {
            return true;
        }

        $lastAvailable = (int) ($workspace->low_credit_warning_last_available ?? 0);
        $currentAvailable = (int) ($evaluation['available_credits'] ?? 0);
        if ($currentAvailable < $lastAvailable) {
            return true;
        }

        $cooldownHours = max(1, (int) config('credits.warnings.resend_cooldown_hours', 24));

        return $sentAt->lte(now()->subHours($cooldownHours));
    }

    /**
     * @param  array<string, mixed>  $evaluation
     */
    private function sendWarningEmail(Workspace $workspace, array $evaluation): bool
    {
        [$users, $emails] = $this->resolveRecipients($workspace);
        if ($users->isEmpty() && $emails === []) {
            return false;
        }

        $locale = $workspace->defaultContentLanguageCode() === 'nl' ? 'nl' : 'en';
        $notification = new LowCreditWarningNotification($evaluation, $locale);

        if ($users->isNotEmpty()) {
            NotificationFacade::locale($locale)->send($users, $notification);
        }

        foreach ($emails as $email) {
            $anonymous = new AnonymousNotifiable();
            $anonymous->route('mail', $email);
            $anonymous->notify((new LowCreditWarningNotification($evaluation, $locale))->locale($locale));
        }

        Log::info('credits.low_warning_mail_sent', [
            'workspace_id' => (string) $workspace->id,
            'user_recipient_count' => $users->count(),
            'email_recipient_count' => count($emails),
            'active_automations' => (int) ($evaluation['active_automation_count'] ?? 0),
        ]);

        return true;
    }

    /**
     * @return array{0:Collection<int, User>,1:array<int, string>}
     */
    private function resolveRecipients(Workspace $workspace): array
    {
        $organization = $workspace->organization()->with(['primaryUser', 'users'])->first();
        if (! $organization) {
            return [collect(), []];
        }

        /** @var Collection<int, User> $users */
        $users = $organization->users
            ->filter(function (User $user): bool {
                if ($user->is_admin) {
                    return false;
                }

                if (! $user->active || ! $user->isApproved()) {
                    return false;
                }

                return in_array((string) $user->role, ['owner', 'admin'], true);
            })
            ->push($organization->primaryUser)
            ->filter(fn (?User $user): bool => $user instanceof User && $user->active && $user->isApproved() && ! $user->is_admin)
            ->unique(fn (User $user): string => strtolower((string) $user->email))
            ->values();

        $userEmails = $users
            ->pluck('email')
            ->filter()
            ->map(fn (string $email): string => strtolower($email))
            ->all();

        $additionalEmails = collect([(string) ($organization->billing_email ?? '')])
            ->map(fn (string $email): string => strtolower(trim($email)))
            ->filter(fn (string $email): bool => $email !== '' && ! in_array($email, $userEmails, true))
            ->values()
            ->all();

        return [$users, $additionalEmails];
    }

    private function resetWorkspaceWarningState(Workspace $workspace): bool
    {
        $this->markWorkspaceWarningNotificationsRead($workspace);

        if (
            $workspace->low_credit_warning_state === null
            && $workspace->low_credit_warning_sent_at === null
            && $workspace->low_credit_warning_last_available === null
        ) {
            return false;
        }

        $workspace->forceFill([
            'low_credit_warning_state' => null,
            'low_credit_warning_sent_at' => null,
            'low_credit_warning_last_available' => null,
        ])->save();

        Log::info('credits.low_warning_reset', [
            'workspace_id' => (string) $workspace->id,
        ]);

        return true;
    }

    private function bodyForEvaluation(bool $hasActiveAutomations, int $automationCount, ?string $nextRunLabel): string
    {
        if (! $hasActiveAutomations) {
            return __('app.credits.low_warning.body');
        }

        return __('app.credits.low_warning.body_with_automation', [
            'count' => $automationCount,
            'next_run' => $nextRunLabel ?: __('app.common.na'),
        ]);
    }

    private function stateKey(
        int $availableCredits,
        bool $isAutomationRisk,
        bool $isBlocking,
        int $absoluteThreshold,
        float $percentageThreshold,
        float $remainingPercentage,
    ): string {
        $prefix = $isAutomationRisk ? 'automation' : 'general';

        if ($isBlocking) {
            return $prefix . ':critical';
        }

        if ($availableCredits <= $absoluteThreshold) {
            $bucketSize = max(1, (int) ceil($absoluteThreshold / 2));

            return $prefix . ':abs:' . intdiv(max(0, $availableCredits), $bucketSize);
        }

        return $prefix . ':pct:' . (int) floor(min($percentageThreshold, $remainingPercentage));
    }

    /**
     * @param  array<string, mixed>  $evaluation
     */
    private function resolveOrCreateWorkspaceWarningNotification(Workspace $workspace, array $evaluation): Notification
    {
        $stateKey = (string) ($evaluation['state_key'] ?? '');

        $existing = Notification::query()
            ->workspaceScoped()
            ->forWorkspace((string) $workspace->id)
            ->unread()
            ->where('type', Notification::TYPE_ACTION_REQUIRED)
            ->where('meta->kind', 'low_credits')
            ->where('meta->state_key', $stateKey)
            ->latest('created_at')
            ->first();

        if ($existing) {
            return $existing;
        }

        $this->markWorkspaceWarningNotificationsRead($workspace);

        return $this->notifications->notifyWorkspace(
            workspaceId: (string) $workspace->id,
            type: Notification::TYPE_ACTION_REQUIRED,
            title: (string) $evaluation['title'],
            body: (string) $evaluation['body'],
            options: [
                'cta_label' => (string) $evaluation['cta_label'],
                'cta_url' => (string) $evaluation['cta_url'],
                'priority' => Notification::PRIORITY_ACTION_REQUIRED,
                'meta' => [
                    'kind' => 'low_credits',
                    'state_key' => $stateKey,
                    'available_credits' => (int) ($evaluation['available_credits'] ?? 0),
                    'active_automation_count' => (int) ($evaluation['active_automation_count'] ?? 0),
                ],
            ],
        );
    }

    private function markWorkspaceWarningNotificationsRead(Workspace $workspace): void
    {
        Notification::query()
            ->workspaceScoped()
            ->forWorkspace((string) $workspace->id)
            ->unread()
            ->where('meta->kind', 'low_credits')
            ->update(['read_at' => now()]);
    }
}
