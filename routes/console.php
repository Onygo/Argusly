<?php

use App\Models\IntegrationConnection;
use App\Services\Integrations\LinkedIn\LinkedInTokenService;
use App\Services\Visibility\RunScheduleService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('visibility:run-due {--limit=50}', function (RunScheduleService $schedules): int {
    $runs = $schedules->runDue((int) $this->option('limit'));

    $this->info("Processed {$runs->count()} due AI visibility run schedule(s).");

    return self::SUCCESS;
})->purpose('Run due AI visibility prompt schedules');

Artisan::command('linkedin:check-token-health {--limit=100}', function (LinkedInTokenService $tokens): int {
    $connections = IntegrationConnection::query()
        ->whereHas('integration', fn ($query) => $query->where('key', 'linkedin'))
        ->whereIn('status', ['active', 'expired'])
        ->whereNotNull('account_id')
        ->with(['integration', 'account', 'brand', 'owner'])
        ->orderBy('id')
        ->limit((int) $this->option('limit'))
        ->get();

    $processed = 0;

    foreach ($connections as $connection) {
        if ($tokens->isExpired($connection) || $tokens->willExpireSoon($connection)) {
            $tokens->refreshIfPossible($connection);
            $processed++;
        }
    }

    $this->info("Checked {$connections->count()} LinkedIn connection(s); processed {$processed} token health update(s).");

    return self::SUCCESS;
})->purpose('Check LinkedIn token expiry and refresh or mark expired when needed');

Schedule::command('linkedin:check-token-health')->hourly();
