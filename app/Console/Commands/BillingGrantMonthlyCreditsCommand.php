<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use App\Services\SubscriptionMonthlyCreditRecoveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class BillingGrantMonthlyCreditsCommand extends Command
{
    protected $signature = 'billing:grant-monthly-credits
        {org_id : Organization ID}
        {--period= : Billing period in YYYY-MM format}
        {--dry-run : Preview only, no writes}
        {--force : Required for write mode}
        {--admin-user-id= : Admin user ID for audit metadata}';

    protected $description = 'Grant missing monthly subscription credits for a billing period with idempotent safeguards.';

    public function handle(SubscriptionMonthlyCreditRecoveryService $recovery): int
    {
        $orgId = (int) $this->argument('org_id');
        $organization = Organization::query()->find($orgId);

        if (! $organization) {
            $this->error('Organization not found.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if (! $dryRun && ! $force) {
            $this->error('Write mode requires --force. Use --dry-run to preview.');

            return self::FAILURE;
        }

        $period = $this->parsePeriod((string) ($this->option('period') ?? ''));
        if ((string) ($this->option('period') ?? '') !== '' && $period === null) {
            $this->error('Invalid --period value. Expected YYYY-MM.');

            return self::FAILURE;
        }

        $adminUser = $this->resolveAdminUser((string) ($this->option('admin-user-id') ?? ''), $dryRun);
        if (! $dryRun && ! $adminUser) {
            return self::FAILURE;
        }

        $result = $recovery->recoverForOrganization(
            organization: $organization,
            period: $period,
            dryRun: $dryRun,
            adminUser: $adminUser,
            trigger: 'artisan',
            requirePaidSignal: false
        );

        $this->line('Organization: ' . $organization->id . ' (' . $organization->name . ')');
        $this->line('Action: ' . (string) ($result['action'] ?? 'unknown'));
        $this->line('Reason: ' . (string) ($result['reason'] ?? ''));
        $this->line('Period: ' . (string) ($result['period_start'] ?? 'n/a') . ' -> ' . (string) ($result['period_end'] ?? 'n/a'));
        $this->line('Amount: ' . (int) ($result['amount'] ?? 0));
        $this->line('Wallet available before/after: ' . (int) ($result['wallet_before_available'] ?? 0) . ' -> ' . (int) ($result['wallet_after_available'] ?? 0));
        $this->line('Ledger ID: ' . (string) ($result['ledger_id'] ?? 'n/a'));

        if (! (bool) ($result['ok'] ?? false)) {
            $this->error((string) ($result['reason'] ?? 'Recovery failed.'));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function parsePeriod(string $value): ?Carbon
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}$/', $trimmed) !== 1) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m', $trimmed)->startOfMonth();
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveAdminUser(string $adminUserId, bool $dryRun): ?User
    {
        $adminUserId = trim($adminUserId);
        if ($adminUserId === '') {
            if (! $dryRun) {
                $this->error('Write mode requires --admin-user-id for audit metadata.');
            }

            return null;
        }

        $admin = User::query()->find($adminUserId);
        if (! $admin || ! (bool) $admin->is_admin) {
            $this->error('admin-user-id must belong to a platform admin.');

            return null;
        }

        return $admin;
    }
}
