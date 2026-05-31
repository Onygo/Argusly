<?php

use App\Models\IntegrationConnection;
use App\Models\Ga4Property;
use App\Models\SearchConsoleSite;
use App\Services\Integrations\Google\GoogleTokenService;
use App\Services\Integrations\Google\GA4DataService;
use App\Services\Integrations\Google\SearchConsolePerformanceService;
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

Artisan::command('google:check-token-health {--limit=100}', function (GoogleTokenService $tokens): int {
    $connections = IntegrationConnection::query()
        ->whereHas('integration', fn ($query) => $query->where('key', 'google'))
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

    $this->info("Checked {$connections->count()} Google connection(s); processed {$processed} token health update(s).");

    return self::SUCCESS;
})->purpose('Check Google token expiry and refresh or mark expired when needed');

Artisan::command('ga4:sync {--account=} {--brand=} {--days=30}', function (GA4DataService $ga4): int {
    $query = Ga4Property::query()
        ->where('status', 'connected')
        ->whereNotNull('integration_connection_id')
        ->with(['account', 'brand', 'integrationConnection.integration'])
        ->orderBy('id');

    if ($this->option('account')) {
        $account = (string) $this->option('account');
        $query->whereHas('account', fn ($scope) => $scope
            ->where('id', is_numeric($account) ? (int) $account : 0)
            ->orWhere('slug', $account));
    }

    if ($this->option('brand')) {
        $brand = (string) $this->option('brand');
        $query->whereHas('brand', fn ($scope) => $scope
            ->where('id', is_numeric($brand) ? (int) $brand : 0)
            ->orWhere('slug', $brand));
    }

    $properties = $query->get();
    $synced = 0;

    foreach ($properties as $property) {
        $ga4->sync($property, (int) $this->option('days'));
        $synced++;
    }

    $this->info("Synced {$synced} GA4 propert".($synced === 1 ? 'y' : 'ies').'.');

    return self::SUCCESS;
})->purpose('Sync GA4 metrics from Google Analytics Data API');

Artisan::command('search-console:sync {--account=} {--brand=} {--days=30}', function (SearchConsolePerformanceService $searchConsole): int {
    $query = SearchConsoleSite::query()
        ->where('status', 'connected')
        ->whereNotNull('integration_connection_id')
        ->with(['account', 'brand', 'integrationConnection.integration'])
        ->orderBy('id');

    if ($this->option('account')) {
        $account = (string) $this->option('account');
        $query->whereHas('account', fn ($scope) => $scope
            ->where('id', is_numeric($account) ? (int) $account : 0)
            ->orWhere('slug', $account));
    }

    if ($this->option('brand')) {
        $brand = (string) $this->option('brand');
        $query->whereHas('brand', fn ($scope) => $scope
            ->where('id', is_numeric($brand) ? (int) $brand : 0)
            ->orWhere('slug', $brand));
    }

    $sites = $query->get();
    $synced = 0;

    foreach ($sites as $site) {
        $searchConsole->sync($site, (int) $this->option('days'));
        $synced++;
    }

    $this->info("Synced {$synced} Search Console ".($synced === 1 ? 'site' : 'sites').'.');

    return self::SUCCESS;
})->purpose('Sync Search Console performance data');

Schedule::command('linkedin:check-token-health')->hourly();
Schedule::command('google:check-token-health')->hourly();
