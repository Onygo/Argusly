<?php

namespace App\Console\Commands;

use App\Jobs\SendOnboardingEmailJob;
use App\Models\OnboardingState;
use App\Services\Onboarding\OnboardingStateService;
use Illuminate\Console\Command;

class OnboardingCheckInactivityCommand extends Command
{
    protected $signature = 'onboarding:check-inactivity {--limit=500}';

    protected $description = 'Evaluate onboarding inactivity and queue PublishLayer re-engagement emails.';

    public function handle(OnboardingStateService $states): int
    {
        $now = now();
        $limit = max(1, (int) $this->option('limit'));
        $queued = 0;

        OnboardingState::query()
            ->with('user')
            ->orderBy('id')
            ->chunkById($limit, function ($rows) use (&$queued, $states, $now): void {
                foreach ($rows as $state) {
                    if (! $state->user || $state->user->is_admin) {
                        continue;
                    }

                    if ((bool) config('publishlayer.onboarding.require_email_verification', false) && ! $state->verified_at) {
                        if (! $state->wasEmailSent('verify_reminder_1') && $state->registered_at && $state->registered_at->lte($now->copy()->subDay())) {
                            SendOnboardingEmailJob::dispatch((int) $state->user_id, 'verify_reminder_1');
                            $queued++;
                        }

                        if (! $state->wasEmailSent('verify_reminder_2') && $state->registered_at && $state->registered_at->lte($now->copy()->subHours(72))) {
                            SendOnboardingEmailJob::dispatch((int) $state->user_id, 'verify_reminder_2');
                            $queued++;
                        }
                    }

                    if ($state->verified_at && ! $state->first_login_at && ! $state->wasEmailSent('nudge_login')) {
                        if ($state->verified_at->lte($now->copy()->subHours(48))) {
                            SendOnboardingEmailJob::dispatch((int) $state->user_id, 'nudge_login');
                            $queued++;
                        }
                    }

                    if ($state->first_login_at && ! $state->first_value_at && ! $state->wasEmailSent('nudge_no_action')) {
                        if ($state->first_login_at->lte($now->copy()->subDays(3))) {
                            SendOnboardingEmailJob::dispatch((int) $state->user_id, 'nudge_no_action');
                            $queued++;
                        }
                    }

                    if ($state->first_value_at && $state->last_activity_at && ! $state->wasEmailSent('reengage')) {
                        if ($state->last_activity_at->lte($now->copy()->subDays(14))) {
                            $states->markCold($state);
                            SendOnboardingEmailJob::dispatch((int) $state->user_id, 'reengage');
                            $queued++;
                        }
                    }

                    if ((bool) config('publishlayer.onboarding.trial_ending_enabled', false) && ! $state->wasEmailSent('trial_ending')) {
                        $trialEndsAt = $states->activeTrialEndsAt($state);
                        if ($trialEndsAt && $trialEndsAt->isFuture() && $trialEndsAt->lte($now->copy()->addDays(3))) {
                            SendOnboardingEmailJob::dispatch((int) $state->user_id, 'trial_ending');
                            $queued++;
                        }
                    }
                }
            });

        $this->info("Queued onboarding emails: {$queued}");

        return self::SUCCESS;
    }
}

