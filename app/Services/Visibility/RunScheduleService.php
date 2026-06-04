<?php

namespace App\Services\Visibility;

use App\Exceptions\InsufficientCreditsException;
use App\Models\VisibilityProviderRun;
use App\Models\VisibilityRunSchedule;
use App\Services\CreditService;
use App\Services\DomainEventService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

class RunScheduleService
{
    public function __construct(
        private readonly ProviderRunService $runs,
        private readonly CreditService $credits,
    ) {}

    /**
     * @return Collection<int, VisibilityProviderRun>
     */
    public function runDue(int $limit = 50): Collection
    {
        return VisibilityRunSchedule::query()
            ->due()
            ->with(['account', 'brandModel', 'promptTemplate'])
            ->orderByRaw('next_run_at is null desc')
            ->orderBy('next_run_at')
            ->limit($limit)
            ->get()
            ->map(fn (VisibilityRunSchedule $schedule) => $this->runSchedule($schedule));
    }

    public function runSchedule(VisibilityRunSchedule $schedule): VisibilityProviderRun
    {
        $template = $schedule->promptTemplate;
        $brand = $schedule->brandModel;
        $account = $schedule->account;
        $cost = $this->credits->cost('ai_visibility_run');
        $language = $schedule->language ?? $template->language;
        $locale = $schedule->locale ?? $template->locale;
        $market = $schedule->market ?? $template->market;
        $persona = $schedule->persona ?? $template->persona;
        $intent = $schedule->intent ?? $template->intent;

        try {
            $run = $this->runs->runPrompt(
                account: $account,
                brand: $brand,
                provider: $schedule->provider,
                prompt: str_replace('{brand}', $brand->name, $template->prompt),
                template: $template,
                context: [
                    'brand' => $brand->name,
                    'language' => $language,
                    'intent' => $intent,
                    'locale' => $locale,
                    'market' => $market,
                    'persona' => $persona,
                    'schedule_id' => $schedule->id,
                    'cost_credits' => $cost,
                ],
            );

            $run->forceFill([
                'cost_credits' => $cost,
                'metadata' => [
                    ...($run->metadata ?? []),
                    'visibility_run_schedule_id' => $schedule->id,
                ],
            ])->save();

            $schedule->forceFill([
                'last_run_at' => $run->captured_at,
                'next_run_at' => $this->nextRunAt($schedule),
            ])->save();

            app(DomainEventService::class)->recordForSubject('VisibilityRunScheduleExecuted', $schedule->refresh(), null, [
                'visibility_provider_run_id' => $run->id,
                'prompt_template_id' => $template->id,
                'provider' => $schedule->provider,
                'frequency' => $schedule->frequency,
                'language' => $language,
                'locale' => $locale,
                'market' => $market,
                'persona' => $persona,
                'intent' => $intent,
                'visibility_score' => $run->metadata['visibility_score'] ?? null,
                'cost_credits' => $cost,
            ], $run->captured_at);

            return $run->refresh();
        } catch (Throwable $exception) {
            $schedule->forceFill([
                'last_run_at' => now(),
                'next_run_at' => $this->nextRunAt($schedule),
                'settings' => [
                    ...($schedule->settings ?? []),
                    'last_error' => $exception->getMessage(),
                    'last_error_at' => now()->toDateTimeString(),
                    'insufficient_credits' => $exception instanceof InsufficientCreditsException,
                ],
            ])->save();

            app(DomainEventService::class)->recordForSubject('VisibilityRunScheduleFailed', $schedule->refresh(), null, [
                'prompt_template_id' => $template->id,
                'provider' => $schedule->provider,
                'frequency' => $schedule->frequency,
                'language' => $language,
                'locale' => $locale,
                'market' => $market,
                'error' => $exception->getMessage(),
                'insufficient_credits' => $exception instanceof InsufficientCreditsException,
            ]);

            throw $exception;
        }
    }

    private function nextRunAt(VisibilityRunSchedule $schedule): ?Carbon
    {
        $anchor = now();

        return match ($schedule->frequency) {
            'daily' => $anchor->copy()->addDay(),
            'weekly' => $anchor->copy()->addWeek(),
            'monthly' => $anchor->copy()->addMonthNoOverflow(),
            'manual' => null,
            default => null,
        };
    }
}
