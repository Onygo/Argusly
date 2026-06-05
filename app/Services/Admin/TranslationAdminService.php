<?php

namespace App\Services\Admin;

use App\Models\Content;
use App\Models\ContentTranslation;
use App\Models\SiteCreditAllocation;
use App\Models\User;
use App\Services\Content\TranslationRecoveryService;
use App\Services\CreditWalletService;
use App\Services\Translation\TranslationLockRepairService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class TranslationAdminService
{
    public function __construct(
        private readonly TranslationLockRepairService $repairService,
        private readonly TranslationRecoveryService $recoveryService,
        private readonly CreditWalletService $creditWallets,
    ) {}

    /**
     * @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,array<string,mixed>>
     */
    public function getTranslations(array $filters = [], string $pageName = 'translation_page', int $perPage = 20): LengthAwarePaginator
    {
        $query = ContentTranslation::query()
            ->with([
                'content:id,workspace_id,client_site_id,family_id,title,language',
                'content.workspace:id,organization_id',
                'content.workspace.organization:id,name,active_subscription_id',
                'content.workspace.organization.activeSubscription:id,plan_id',
                'content.workspace.organization.activeSubscription.plan:id,name',
                'content.clientSite:id,name',
                'targetContent:id,title',
            ])
            ->whereHas('content');

        $organization = trim((string) ($filters['organization'] ?? ''));
        if ($organization !== '') {
            $query->whereHas('content.workspace.organization', function ($builder) use ($organization): void {
                $builder->where('organizations.id', $organization)
                    ->orWhere('organizations.name', 'like', '%' . $organization . '%');
            });
        }

        $site = trim((string) ($filters['site'] ?? ''));
        if ($site !== '') {
            $query->whereHas('content.clientSite', function ($builder) use ($site): void {
                $builder->where('client_sites.id', $site)
                    ->orWhere('client_sites.name', 'like', '%' . $site . '%');
            });
        }

        $contentId = trim((string) ($filters['content_id'] ?? ''));
        if ($contentId !== '') {
            $query->where('content_id', $contentId);
        }

        $locale = trim((string) ($filters['locale'] ?? ''));
        if ($locale !== '') {
            $query->where('target_locale', $locale);
        }

        $status = trim((string) ($filters['status'] ?? ''));
        $requiresStaleInspection = $status === 'stale' || (bool) ($filters['stale_only'] ?? false);
        if ($status !== '') {
            if ($status !== 'stale') {
                $query->where('status', $status);
            }
        }

        if ((bool) ($filters['failed_only'] ?? false)) {
            $query->where('status', ContentTranslation::STATUS_FAILED);
        }

        $staleTranslationIds = $requiresStaleInspection
            ? $this->resolveStaleTranslationIds(clone $query)
            : [];

        if ($status === 'stale' || (bool) ($filters['stale_only'] ?? false)) {
            $query->whereIn('id', $staleTranslationIds !== [] ? $staleTranslationIds : ['__none__']);
        }

        $paginator = $query
            ->latest('updated_at')
            ->latest('created_at')
            ->paginate($perPage, ['*'], $pageName)
            ->withQueryString();

        $pageTranslations = collect($paginator->items());
        $inspectedRows = $this->inspectPageTranslations($pageTranslations)->keyBy(
            fn (array $row): string => (string) $row['translation']->id
        );
        $creditBalances = $this->creditBalancesForPage($pageTranslations);

        return $paginator->through(function (ContentTranslation $translation) use ($inspectedRows, $creditBalances): array {
                $content = $translation->content;
                $inspection = $inspectedRows->get((string) $translation->id, [
                    'reason' => '',
                    'pending_jobs' => collect(),
                    'linked_failed_jobs' => collect(),
                    'attempts' => 0,
                    'lock_state' => (string) $translation->status,
                ]);
                $failedJobs = $inspection['linked_failed_jobs'];
                $latestFailedJob = $failedJobs->first();
                $staleReason = $inspection['reason'] !== '' ? $inspection['reason'] : null;
                $displayState = match ($inspection['lock_state']) {
                    'locked' => 'Locked',
                    'stale_lock' => 'Stale lock',
                    'failed_with_stale_lock' => 'Failed with stale lock',
                    'retry_queued' => 'Retry queued',
                    'stale_recovered' => 'Stale recovered',
                    default => $translation->isInsufficientCreditsFailure()
                        ? 'Not enough credits'
                        : ucfirst(str_replace('_', ' ', (string) ($inspection['lock_state'] ?? $translation->displayStatus()))),
                };

                return [
                    'id' => (string) $translation->id,
                    'content_title' => $content?->title ?? 'Unknown content',
                    'content_id' => (string) $translation->content_id,
                    'family_id' => (string) ($content?->family_id ?? $translation->content_id),
                    'source_locale' => $this->normalizeLocale($content?->language),
                    'target_locale' => $this->normalizeLocale($translation->target_locale),
                    'status' => (string) $translation->status,
                    'display_status' => $staleReason !== null ? 'stale' : (string) $translation->displayStatus(),
                    'display_state' => $displayState,
                    'locked_at' => $translation->updated_at ?? $translation->created_at,
                    'locked_by_job_id' => $translation->job_id,
                    'job_uuid' => $translation->processing_job_uuid,
                    'processing_started_at' => $translation->processing_started_at,
                    'processing_locked_at' => $translation->processing_locked_at,
                    'processing_last_heartbeat_at' => $translation->processing_last_heartbeat_at,
                    'processing_failed_at' => $translation->processing_failed_at,
                    'failure_reason' => $translation->failure_reason,
                    'required_credits' => $translation->required_credits,
                    'available_credits' => $translation->available_credits,
                    'entitlement_source' => $translation->entitlement_source,
                    'credit_balance' => $content?->client_site_id
                        ? ($creditBalances[(string) $content->client_site_id] ?? null)
                        : null,
                    'plan_name' => $content?->workspace?->organization?->activeSubscription?->plan?->name,
                    'attempts' => (int) ($inspection['attempts'] ?? $this->resolveAttempts($translation, $latestFailedJob)),
                    'last_error' => $translation->displayErrorMessage() ?: ($latestFailedJob['error_summary'] ?? null),
                    'created_at' => $translation->created_at,
                    'updated_at' => $translation->updated_at,
                    'content_url' => $content instanceof Content ? route('app.content.show', $content) : null,
                    'organization' => $content?->workspace?->organization?->name,
                    'organization_id' => $content?->workspace?->organization?->id,
                    'site' => $content?->clientSite?->name,
                    'site_id' => $content?->clientSite?->id,
                    'is_stale' => $staleReason !== null,
                    'stale_reason' => $staleReason,
                    'lock_state' => (string) ($inspection['lock_state'] ?? ''),
                    'pending_jobs_count' => $inspection['pending_jobs']->count(),
                    'failed_jobs' => $failedJobs,
                    'latest_failed_job' => $latestFailedJob,
                    'failed_jobs_count' => $failedJobs->count(),
                    'is_completed' => (string) $translation->status === ContentTranslation::STATUS_COMPLETED,
                    'can_recover' => (string) $translation->status !== ContentTranslation::STATUS_COMPLETED,
                    'status_tone' => match (true) {
                        $staleReason !== null => 'amber',
                        (string) $translation->status === ContentTranslation::STATUS_COMPLETED => 'green',
                        (string) $translation->status === ContentTranslation::STATUS_FAILED => 'red',
                        in_array((string) $translation->status, [ContentTranslation::STATUS_QUEUED, ContentTranslation::STATUS_PROCESSING], true) => 'sky',
                        default => 'slate',
                    },
                    'recovery_hint' => $staleReason !== null ? 'Detected stale lock. Safe recovery available.' : null,
                ];
            });
    }

    /**
     * @return array<int,string>
     */
    private function resolveStaleTranslationIds(Builder $query): array
    {
        $translations = (clone $query)
            ->whereIn('status', [
                ContentTranslation::STATUS_QUEUED,
                ContentTranslation::STATUS_PROCESSING,
                ContentTranslation::STATUS_FAILED,
            ])
            ->get([
                'id',
                'content_id',
                'target_locale',
                'target_content_id',
                'status',
                'failure_reason',
                'job_id',
                'processing_started_at',
                'processing_job_uuid',
                'processing_locked_at',
                'processing_last_heartbeat_at',
                'processing_failed_at',
                'processing_last_recovered_at',
                'processing_error_message',
                'processing_recovery_count',
                'error_message',
                'updated_at',
                'created_at',
            ]);

        return $this->repairService->inspectTranslations($translations)
            ->filter(fn (array $row): bool => (string) ($row['reason'] ?? '') !== '')
            ->pluck('translation.id')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }

    /**
     * @param  Collection<int,ContentTranslation>  $translations
     * @return Collection<int,array<string,mixed>>
     */
    private function inspectPageTranslations(Collection $translations): Collection
    {
        $candidates = $translations
            ->filter(fn (ContentTranslation $translation): bool => in_array((string) $translation->status, [
                ContentTranslation::STATUS_QUEUED,
                ContentTranslation::STATUS_PROCESSING,
                ContentTranslation::STATUS_FAILED,
            ], true))
            ->values();

        if ($candidates->isEmpty()) {
            return collect();
        }

        return $this->repairService->inspectTranslations($candidates);
    }

    /**
     * @param  Collection<int,ContentTranslation>  $translations
     * @return array<string,int>
     */
    private function creditBalancesForPage(Collection $translations): array
    {
        $siteIds = $translations
            ->pluck('content.client_site_id')
            ->filter()
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values();

        if ($siteIds->isEmpty()) {
            return [];
        }

        return SiteCreditAllocation::query()
            ->whereIn('client_site_id', $siteIds->all())
            ->get(['client_site_id', 'allocated_credits', 'reserved_cached'])
            ->mapWithKeys(fn (SiteCreditAllocation $allocation): array => [
                (string) $allocation->client_site_id => [(int) $allocation->remaining],
            ])
            ->map(fn (array $value): int => (int) ($value[0] ?? 0))
            ->all();
    }

    /**
     * @return array<string,string|bool>
     */
    public function releaseLock(ContentTranslation $translation, ?User $actor = null, ?Request $request = null): array
    {
        return $this->recoveryService->releaseLock($translation, $actor, $request);
    }

    /**
     * @return array<string,string|bool>
     */
    public function markAsFailed(ContentTranslation $translation, ?User $actor = null, ?Request $request = null): array
    {
        return $this->recoveryService->markAsFailed($translation, $actor, $request);
    }

    /**
     * @return array<string,string|bool>
     */
    public function retryTranslation(ContentTranslation $translation, ?User $actor = null, ?Request $request = null): array
    {
        return $this->recoveryService->retryExistingTranslation($translation, $actor, $request);
    }

    /**
     * @return array<string,string|bool>
     */
    public function forceResetAndRetry(ContentTranslation $translation, ?User $actor = null, ?Request $request = null): array
    {
        return $this->recoveryService->forceResetAndRetry($translation, $actor, $request);
    }

    /**
     * @return array{ok:bool,message:string,found_count:int,fixed_count:int}
     */
    public function repairStaleLocks(bool $apply = false, int $limit = 250, ?User $actor = null, ?Request $request = null): array
    {
        $result = $this->repairService->repair(limit: $limit, apply: $apply, includeFailed: true);

        if ($apply) {
            Log::warning('translation.admin.repair_stale_locks', [
                'actor_id' => $actor?->id,
                'found_count' => $result['found_count'],
                'fixed_count' => $result['fixed_count'],
            ]);
        }

        return [
            'ok' => true,
            'message' => $apply
                ? sprintf('Repair completed: %d stale translation lock(s) found, %d fixed.', $result['found_count'], $result['fixed_count'])
                : sprintf('Dry run complete: %d stale translation lock(s) found.', $result['found_count']),
            'found_count' => $result['found_count'],
            'fixed_count' => $result['fixed_count'],
        ];
    }

    private function resolveAttempts(ContentTranslation $translation, ?array $latestFailedJob): int
    {
        if (is_array($latestFailedJob) && isset($latestFailedJob['attempts'])) {
            return (int) $latestFailedJob['attempts'];
        }

        return 0;
    }

    private function normalizeLocale(mixed $locale): string
    {
        if ($locale instanceof \BackedEnum) {
            return (string) $locale->value;
        }

        return trim((string) $locale);
    }
}
