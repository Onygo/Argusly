<?php

namespace App\Console\Commands;

use App\Services\CreditReservationService;
use Illuminate\Console\Command;

class CreditsExpireReservationsCommand extends Command
{
    protected $signature = 'credits:expire-reservations
        {--limit=100 : Maximum reservations to expire per run}
        {--dry-run : Show what would be expired without making changes}';

    protected $description = 'Expire stale credit reservations that have passed their TTL';

    public function handle(CreditReservationService $reservationService): int
    {
        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $stale = \App\Models\CreditReservation::query()
                ->stale()
                ->orderBy('expires_at')
                ->limit($limit)
                ->get();

            $this->info("Would expire {$stale->count()} reservations:");

            foreach ($stale as $reservation) {
                $this->line(sprintf(
                    '  - %s: %d credits, purpose=%s, expired_at=%s',
                    $reservation->id,
                    $reservation->amount,
                    $reservation->purpose,
                    $reservation->expires_at?->toDateTimeString() ?? 'N/A'
                ));
            }

            return self::SUCCESS;
        }

        $expired = $reservationService->expireStaleReservations($limit);

        if ($expired > 0) {
            $this->info("Expired {$expired} stale credit reservations.");
        } else {
            $this->info('No stale reservations to expire.');
        }

        return self::SUCCESS;
    }
}
