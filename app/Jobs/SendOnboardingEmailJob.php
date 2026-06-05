<?php

namespace App\Jobs;

use App\Mail\OnboardingEmail;
use App\Models\OnboardingState;
use App\Models\User;
use App\Services\Onboarding\OnboardingStateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendOnboardingEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly int $userId,
        public readonly string $emailKey
    ) {
        $this->onQueue('emails');
    }

    public function handle(OnboardingStateService $states): void
    {
        $state = DB::transaction(function () use ($states): ?OnboardingState {
            /** @var OnboardingState|null $state */
            $lockedState = OnboardingState::query()
                ->where('user_id', $this->userId)
                ->lockForUpdate()
                ->first();

            /** @var User|null $user */
            $user = User::query()->find($this->userId);

            if (! $lockedState || ! $user || trim((string) $user->email) === '' || $user->is_admin) {
                return null;
            }

            if ($lockedState->wasEmailSent($this->emailKey)) {
                return null;
            }

            // Pre-mark to enforce idempotency across retries.
            $states->markEmailSent($lockedState, $this->emailKey);

            return $lockedState->fresh();
        });

        if (! $state) {
            return;
        }

        $user = User::query()->find($this->userId);
        if (! $user || trim((string) $user->email) === '') {
            return;
        }

        Mail::to($user->email)->send(new OnboardingEmail($state, $this->emailKey));
    }
}
