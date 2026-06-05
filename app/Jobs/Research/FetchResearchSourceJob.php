<?php

namespace App\Jobs\Research;

use App\Enums\ResearchSourceFetchStatus;
use App\Models\CreditReservation;
use App\Models\ResearchSource;
use App\Services\CreditReservationService;
use App\Services\CreditWalletService;
use App\Services\Research\SourceIngestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class FetchResearchSourceJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function __construct(
        public readonly string $sourceId,
    ) {
        $this->onQueue((string) config('research.queue', 'research'));
    }

    public function uniqueId(): string
    {
        return 'research:fetch:' . $this->sourceId;
    }

    public function handle(
        SourceIngestionService $ingestion,
        CreditReservationService $reservations,
        CreditWalletService $creditWallet,
    ): void {
        $source = ResearchSource::query()
            ->with('project')
            ->find($this->sourceId);

        if (! $source || ! $source->project) {
            return;
        }

        $project = $source->project;

        if (
            (string) ($source->fetch_status?->value ?? $source->fetch_status) === ResearchSourceFetchStatus::FETCHED->value
            && trim((string) ($source->content_text ?? '')) !== ''
        ) {
            ExtractResearchFindingsJob::dispatch((string) $source->id)
                ->onQueue((string) config('research.queue', 'research'))
                ->afterCommit();

            RunResearchJob::dispatch((string) $project->id)
                ->onQueue((string) config('research.queue', 'research'))
                ->afterCommit();

            return;
        }

        $reservation = null;
        $billing = $this->billingConfig($project);

        try {
            if ($billing['enabled']) {
                $available = $creditWallet->getAvailableForClientSite((string) $project->client_site_id);
                if ($available < $billing['credits_per_source']) {
                    $source->update([
                        'fetch_status' => ResearchSourceFetchStatus::FAILED,
                        'meta' => array_replace_recursive(is_array($source->meta) ? $source->meta : [], [
                            'fetch' => [
                                'status' => ResearchSourceFetchStatus::FAILED->value,
                                'failed_at' => now()->toIso8601String(),
                                'error' => sprintf(
                                    'Insufficient credits for source fetch. Required %d, available %d.',
                                    $billing['credits_per_source'],
                                    $available
                                ),
                            ],
                        ]),
                    ]);

                    RunResearchJob::dispatch((string) $project->id)
                        ->onQueue((string) config('research.queue', 'research'))
                        ->afterCommit();

                    return;
                }

                $reservation = $reservations->reserve(
                    clientSiteId: (string) $project->client_site_id,
                    amount: $billing['credits_per_source'],
                    idempotencyKey: $this->reservationKey($source),
                    purpose: 'research_source_fetch',
                    context: $source,
                    options: [
                        'metadata' => [
                            'research_project_id' => (string) $project->id,
                            'research_source_id' => (string) $source->id,
                        ],
                    ],
                );
            }

            $source = $ingestion->fetchSource($source);
            $sourceStatus = (string) ($source->fetch_status?->value ?? $source->fetch_status);

            if ($billing['enabled'] && $reservation instanceof CreditReservation) {
                if ($sourceStatus === ResearchSourceFetchStatus::FETCHED->value) {
                    $reservations->capture($reservation, [
                        'metadata' => [
                            'feature' => 'research',
                            'event' => 'source_fetch_succeeded',
                        ],
                    ]);
                } elseif ($reservation->isReserved()) {
                    $reservations->release($reservation, 'research_source_fetch_failed', [
                        'failureCode' => 'research_source_fetch_failed',
                        'failureMessage' => (string) data_get($source->meta, 'fetch.error', 'Source fetch failed.'),
                    ]);
                }
            }

            if ($sourceStatus === ResearchSourceFetchStatus::FETCHED->value) {
                ExtractResearchFindingsJob::dispatch((string) $source->id)
                    ->onQueue((string) config('research.queue', 'research'))
                    ->afterCommit();
            }
        } catch (Throwable $exception) {
            if ($billing['enabled']) {
                $reservation = $reservation ?: CreditReservation::query()
                    ->where('idempotency_key', $this->reservationKey($source))
                    ->latest('created_at')
                    ->first();

                if ($reservation instanceof CreditReservation && $reservation->isReserved()) {
                    try {
                        $reservations->release($reservation, 'research_source_fetch_exception', [
                            'failureCode' => 'research_source_fetch_exception',
                            'failureMessage' => $exception->getMessage(),
                        ]);
                    } catch (Throwable) {
                        // Best effort release.
                    }
                }
            }

            throw $exception;
        } finally {
            RunResearchJob::dispatch((string) $project->id)
                ->onQueue((string) config('research.queue', 'research'))
                ->afterCommit();
        }
    }

    public function failed(Throwable $exception): void
    {
        $source = ResearchSource::query()
            ->with('project')
            ->find($this->sourceId);

        if (! $source || ! $source->project) {
            return;
        }

        if ((string) ($source->fetch_status?->value ?? $source->fetch_status) !== ResearchSourceFetchStatus::FETCHED->value) {
            $source->update([
                'fetch_status' => ResearchSourceFetchStatus::FAILED,
                'meta' => array_replace_recursive(is_array($source->meta) ? $source->meta : [], [
                    'fetch' => [
                        'status' => ResearchSourceFetchStatus::FAILED->value,
                        'failed_at' => now()->toIso8601String(),
                        'error' => mb_substr($exception->getMessage(), 0, 1000),
                    ],
                ]),
            ]);
        }

        RunResearchJob::dispatch((string) $source->project->id)
            ->onQueue((string) config('research.queue', 'research'));
    }

    private function reservationKey(ResearchSource $source): string
    {
        return 'research_source:' . (string) $source->id . ':fetch';
    }

    /**
     * @return array{enabled:bool,credits_per_source:int}
     */
    private function billingConfig($project): array
    {
        $enabled = (bool) data_get(
            $project->config,
            'billing.enabled',
            (bool) config('research.billing.enabled_by_default', false)
        );

        $creditsPerSource = max(
            0,
            (int) data_get(
                $project->config,
                'billing.credits_per_source',
                (int) config('research.billing.credits_per_source', 1)
            )
        );

        return [
            'enabled' => $enabled && $creditsPerSource > 0 && trim((string) ($project->client_site_id ?? '')) !== '',
            'credits_per_source' => $creditsPerSource,
        ];
    }
}
