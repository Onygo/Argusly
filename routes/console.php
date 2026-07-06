<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\Analytics\BuildAnalyticsRollupsJob;
use App\Jobs\Analytics\PurgeOldAnalyticsEventsJob;
use App\Jobs\BillingBackfillMonthlyCreditsJob;
use App\Jobs\CreditExpiryJob;
use App\Jobs\CreditResetJob;
use App\Jobs\DetectDuplicateCanonicalIssuesJob;
use App\Jobs\DetectRedirectChainsJob;
use App\Jobs\DunningJob;
use App\Jobs\MandateActivationRetryJob;
use App\Jobs\LlmTracking\BuildLlmTrackingAggregatesJob;
use App\Jobs\SyncSearchConsoleIndexationJob;
use App\Jobs\Stats\RecalculateContentMetricsJob;
use App\Jobs\Stats\RecalculateAiSeoScoresJob;
use App\Jobs\Stats\UpdateContentAiVisibilityJob;
use App\Jobs\ValidateCanonicalIntegrityJob;
use App\Jobs\ValidateSitemapEntriesJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$scheduleLogPath = storage_path('logs/schedule');
File::ensureDirectoryExists($scheduleLogPath);

// Argusly brief pipeline:
// 1. Process incoming briefs from the app and WordPress.
// 2. Generate drafts for briefs that are ready.
// 3. Deliver completed drafts back to their origin.
//
// These steps are intentionally scheduled in order and kept synchronous so
// downstream commands do not start before upstream work has had a chance to run.
Schedule::command('argusly:processBriefs')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->appendOutputTo($scheduleLogPath . '/argusly-process-briefs.log');

Schedule::command('argusly:generateDrafts')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->appendOutputTo($scheduleLogPath . '/argusly-generate-drafts.log');

Schedule::command('argusly:deliverDrafts')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->appendOutputTo($scheduleLogPath . '/argusly-deliver-drafts.log');

Schedule::command('drafts:dispatch-deliveries --limit=25')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('queue:worker-heartbeat')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::job(new CreditResetJob())
    ->hourly()
    ->withoutOverlapping();

Schedule::job(new CreditExpiryJob())
    ->dailyAt('01:00')
    ->withoutOverlapping();

Schedule::job(new BillingBackfillMonthlyCreditsJob())
    ->dailyAt('01:30')
    ->withoutOverlapping();

Schedule::job(new MandateActivationRetryJob())
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::job(new DunningJob())
    ->hourly()
    ->withoutOverlapping();

Schedule::command('onboarding:check-inactivity')
    ->dailyAt('08:00')
    ->withoutOverlapping();

Schedule::command('llm-tracking:dispatch-daily --max-dispatch=120 --queue=default')
    ->hourlyAt(7)
    ->withoutOverlapping();

Schedule::job(new BuildLlmTrackingAggregatesJob())
    ->dailyAt('02:15')
    ->withoutOverlapping();

Schedule::command('content:dispatch-scheduled-publishes --limit=50')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('social:dispatch-scheduled-publications --limit=50')
    ->everyMinute()
    ->withoutOverlapping()
    ->appendOutputTo($scheduleLogPath . '/social-dispatch-scheduled-publications.log');

Schedule::command('content:run-automations --limit=25')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('credits:check-low-balance-warnings --limit=100')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('support:cleanup-snapshots --days=7')
    ->dailyAt('03:10')
    ->withoutOverlapping();

Schedule::command('page-intelligence:run-scheduled-briefings --limit=50')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->appendOutputTo($scheduleLogPath . '/page-intelligence-run-scheduled-briefings.log');

Schedule::command('billing:diagnose-mollie-webhook-gaps --hours=6 --limit=300 --notify-email=dev@argusly.com --alert-cooldown-minutes=120 --fail-on-issues')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::command('generations:reconcile-stale --limit=250')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::command('credits:expire-reservations --limit=100')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('access-overrides:expire')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::job(new BuildAnalyticsRollupsJob())
    ->hourly()
    ->withoutOverlapping();

Schedule::job(new PurgeOldAnalyticsEventsJob())
    ->dailyAt('02:30')
    ->withoutOverlapping();

Schedule::job(new RecalculateContentMetricsJob())
    ->dailyAt('02:40')
    ->withoutOverlapping();

Schedule::job(new UpdateContentAiVisibilityJob())
    ->dailyAt('02:50')
    ->withoutOverlapping();

Schedule::job(new RecalculateAiSeoScoresJob())
    ->dailyAt('03:00')
    ->withoutOverlapping();

Schedule::command('agents:dispatch-scheduled-scans --site-limit=10 --content-limit=25 --queue=default')
    ->hourlyAt(20)
    ->withoutOverlapping();

// Safe autonomous Agentic Marketing runner.
// Intentionally not scheduled aggressively by default: enable only after
// workspace policies, queues, and monitoring are ready, e.g. hourly or daily:
// Schedule::command('agentic:run-autonomous --limit=10')
//     ->hourlyAt(35)
//     ->withoutOverlapping()
//     ->appendOutputTo($scheduleLogPath . '/agentic-run-autonomous.log');

Schedule::job(new ValidateCanonicalIntegrityJob())
    ->dailyAt('03:20')
    ->withoutOverlapping();

Schedule::job(new DetectDuplicateCanonicalIssuesJob())
    ->dailyAt('03:25')
    ->withoutOverlapping();

Schedule::job(new ValidateSitemapEntriesJob())
    ->dailyAt('03:30')
    ->withoutOverlapping();

Schedule::job(new DetectRedirectChainsJob())
    ->dailyAt('03:35')
    ->withoutOverlapping();

Schedule::job(new SyncSearchConsoleIndexationJob())
    ->dailyAt('03:40')
    ->withoutOverlapping();
