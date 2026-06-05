<?php

namespace App\Console\Commands;

use App\Domain\AccessOverrides\AccessOverrideResolver;
use Illuminate\Console\Command;

class ExpireAccessOverridesCommand extends Command
{
    protected $signature = 'access-overrides:expire';

    protected $description = 'Expire access overrides whose end date has passed.';

    public function handle(AccessOverrideResolver $resolver): int
    {
        $expired = $resolver->expireDueOverrides();

        if ($expired === 0) {
            $this->info('No access overrides needed expiration.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Expired %d access overrides.', $expired));

        return self::SUCCESS;
    }
}
