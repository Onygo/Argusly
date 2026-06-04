<?php

use App\Models\Account;
use App\Models\Brand;
use App\Models\Ga4Property;
use App\Models\IntegrationConnection;
use App\Models\Module;
use App\Models\Plan;
use App\Models\Role;
use App\Models\SearchConsoleSite;
use App\Models\User;
use App\Services\Graph\GraphProjectionService;
use App\Services\Integrations\Google\GA4DataService;
use App\Services\Integrations\Google\GoogleTokenService;
use App\Services\Integrations\Google\SearchConsolePerformanceService;
use App\Services\Integrations\LinkedIn\LinkedInTokenService;
use App\Services\Visibility\RunScheduleService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('argusly:bootstrap-admin {email} {--password=} {--account=Argusly} {--brand=Argusly}', function (): int {
    $email = strtolower((string) $this->argument('email'));
    $password = $this->option('password') ?: str()->random(32);
    $accountName = (string) $this->option('account');
    $brandName = (string) $this->option('brand');

    $this->call('db:seed', ['--class' => RolesAndPermissionsSeeder::class]);
    $this->call('db:seed', ['--class' => SubscriptionCatalogSeeder::class]);

    DB::transaction(function () use ($email, $password, $accountName, $brandName): void {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => 'platform_admin',
                'password' => Hash::make($password),
            ],
        );

        if ($user->name !== 'platform_admin') {
            $user->forceFill(['name' => 'platform_admin'])->save();
        }

        $role = Role::query()->updateOrCreate(
            ['name' => 'platform_admin'],
            [
                'display_name' => 'Platform Admin',
                'description' => 'Global platform administrator.',
                'all_permissions' => true,
                'is_system' => true,
                'priority' => 110,
            ],
        );

        DB::table('user_roles')->updateOrInsert(
            [
                'user_id' => $user->id,
                'role_id' => $role->id,
                'account_id' => null,
                'brand_id' => null,
            ],
            [
                'starts_at' => null,
                'expires_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $account = Account::query()->firstOrCreate(
            ['slug' => str($accountName)->slug()->toString()],
            ['name' => $accountName, 'status' => 'active'],
        );

        $brand = Brand::query()->firstOrCreate(
            ['account_id' => $account->id, 'slug' => str($brandName)->slug()->toString()],
            ['name' => $brandName, 'domain' => 'argusly.com', 'status' => 'active'],
        );

        DB::table('memberships')->updateOrInsert(
            ['user_id' => $user->id, 'account_id' => $account->id],
            ['status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        );

        DB::table('brand_memberships')->updateOrInsert(
            ['user_id' => $user->id, 'brand_id' => $brand->id],
            ['account_id' => $account->id, 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        );

        $plan = Plan::query()->where('key', 'starter_monthly')->firstOrFail();
        $core = Module::query()->where('key', 'core')->firstOrFail();

        DB::table('subscriptions')->updateOrInsert(
            ['account_id' => $account->id, 'provider' => 'manual', 'provider_subscription_id' => 'bootstrap-admin'],
            [
                'plan_id' => $plan->id,
                'status' => 'active',
                'billing_interval' => 'monthly',
                'currency' => 'EUR',
                'amount' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $subscription = DB::table('subscriptions')
            ->where('account_id', $account->id)
            ->where('provider', 'manual')
            ->where('provider_subscription_id', 'bootstrap-admin')
            ->first();

        DB::table('subscription_modules')->updateOrInsert(
            ['subscription_id' => $subscription->id, 'module_id' => $core->id],
            [
                'account_id' => $account->id,
                'status' => 'active',
                'starts_at' => null,
                'ends_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    });

    $this->info("Platform admin bootstrapped for {$email}.");

    if (! $this->option('password')) {
        $this->warn("User was created with generated password: {$password}");
        $this->warn('If the user already existed, the password was not changed.');
    }

    return self::SUCCESS;
})->purpose('Create or repair the first platform admin, workspace, brand, and core module access');

Artisan::command('visibility:run-due {--limit=50}', function (RunScheduleService $schedules): int {
    $runs = $schedules->runDue((int) $this->option('limit'));

    $this->info("Processed {$runs->count()} due AI visibility run schedule(s).");

    return self::SUCCESS;
})->purpose('Run due AI visibility prompt schedules');

Artisan::command('graph:rebuild {--account=} {--brand=}', function (GraphProjectionService $graph): int {
    $account = $this->option('account')
        ? Account::query()->where('id', is_numeric($this->option('account')) ? (int) $this->option('account') : 0)->orWhere('slug', $this->option('account'))->firstOrFail()
        : null;

    $brand = $this->option('brand')
        ? Brand::query()
            ->when($account, fn ($query) => $query->where('account_id', $account->id))
            ->where(fn ($query) => $query->where('id', is_numeric($this->option('brand')) ? (int) $this->option('brand') : 0)->orWhere('slug', $this->option('brand')))
            ->firstOrFail()
        : null;

    $result = $graph->rebuild($account, $brand);

    $this->info("Knowledge graph rebuilt: {$result['nodes']} node(s), {$result['edges']} edge(s), {$result['invalidEdges']} invalid edge(s).");

    return $result['invalidEdges'] === 0 ? self::SUCCESS : self::FAILURE;
})->purpose('Rebuild the knowledge graph projection from source tables');

Artisan::command('graph:verify {--account=} {--brand=}', function (GraphProjectionService $graph): int {
    $account = $this->option('account')
        ? Account::query()->where('id', is_numeric($this->option('account')) ? (int) $this->option('account') : 0)->orWhere('slug', $this->option('account'))->firstOrFail()
        : null;

    $brand = $this->option('brand')
        ? Brand::query()
            ->when($account, fn ($query) => $query->where('account_id', $account->id))
            ->where(fn ($query) => $query->where('id', is_numeric($this->option('brand')) ? (int) $this->option('brand') : 0)->orWhere('slug', $this->option('brand')))
            ->firstOrFail()
        : null;

    $result = $graph->verify($account, $brand);

    $this->info("Knowledge graph verified: {$result['nodes']} node(s), {$result['edges']} edge(s), {$result['invalidEdges']} invalid edge(s).");

    return $result['invalidEdges'] === 0 ? self::SUCCESS : self::FAILURE;
})->purpose('Verify knowledge graph projection tenant and edge integrity');

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
