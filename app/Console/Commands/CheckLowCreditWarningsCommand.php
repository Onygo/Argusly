<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Services\Credits\CreditWarningService;
use Illuminate\Console\Command;

class CheckLowCreditWarningsCommand extends Command
{
    protected $signature = 'credits:check-low-balance-warnings
        {--organization= : Limit evaluation to one organization id}
        {--workspace= : Limit evaluation to one workspace id}
        {--limit=100 : Maximum number of workspaces to inspect}';

    protected $description = 'Evaluate low-credit warning state and send throttled alerts.';

    public function handle(CreditWarningService $warnings): int
    {
        if (! $warnings->warningsEnabled()) {
            $this->info('Low-credit warnings are disabled.');

            return self::SUCCESS;
        }

        $organizationId = $this->optionAsInt('organization');
        $workspaceId = $this->optionAsString('workspace');
        $limit = max(1, (int) ($this->option('limit') ?: 100));

        $workspaces = Workspace::query()
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->when($workspaceId !== null, fn ($query) => $query->whereKey($workspaceId))
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        if ($workspaces->isEmpty()) {
            $this->info('No workspaces found for low-credit checks.');

            return self::SUCCESS;
        }

        $warningCount = 0;
        $mailCount = 0;
        $resetCount = 0;

        foreach ($workspaces as $workspace) {
            $result = $warnings->syncWorkspaceWarning($workspace);

            if ((bool) ($result['is_low'] ?? false)) {
                $warningCount++;
                $this->line(sprintf(
                    'Low-credit warning active [%s] available=%d automations=%d',
                    (string) $workspace->id,
                    (int) ($result['available_credits'] ?? 0),
                    (int) ($result['active_automation_count'] ?? 0),
                ));
            }

            if ((bool) ($result['warning_sent'] ?? false)) {
                $mailCount++;
            }

            if ((bool) ($result['reset'] ?? false)) {
                $resetCount++;
            }
        }

        $this->info(sprintf(
            'Processed %d workspace(s); warnings active: %d; emails sent: %d; resets: %d.',
            $workspaces->count(),
            $warningCount,
            $mailCount,
            $resetCount,
        ));

        return self::SUCCESS;
    }

    private function optionAsInt(string $name): ?int
    {
        $value = $this->option($name);

        return is_numeric($value) ? (int) $value : null;
    }

    private function optionAsString(string $name): ?string
    {
        $value = trim((string) ($this->option($name) ?? ''));

        return $value !== '' ? $value : null;
    }
}
