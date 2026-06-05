<?php

namespace App\Console\Commands;

use App\Jobs\ContentAutomation\RunContentAutomationJob;
use App\Models\ContentAutomation;
use App\Services\Credits\CreditWarningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunContentAutomationsCommand extends Command
{
    protected $signature = 'content:run-automations
        {--organization= : Limit dispatch to one organization id}
        {--workspace= : Limit dispatch to one workspace id}
        {--site= : Limit dispatch to one client site id}
        {--limit=25 : Maximum number of automations to dispatch}';

    protected $description = 'Dispatch due autonomous content chain runs.';

    public function handle(): int
    {
        $creditWarnings = app(CreditWarningService::class);
        $organizationId = $this->optionAsInt('organization');
        $workspaceId = $this->optionAsString('workspace');
        $siteId = $this->optionAsString('site');
        $limit = max(1, (int) ($this->option('limit') ?: 25));

        $now = now();

        $automations = ContentAutomation::query()
            ->where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->when($workspaceId !== null, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->when($siteId !== null, fn ($query) => $query->where('client_site_id', $siteId))
            ->orderBy('next_run_at')
            ->limit($limit)
            ->get();

        if ($automations->isEmpty()) {
            $this->info('No due content automations found.');

            return self::SUCCESS;
        }

        $dispatched = 0;

        foreach ($automations as $automation) {
            $skipReason = $automation->skipReason($now);

            if ($skipReason !== null) {
                $this->logSkip($automation, $skipReason);

                if (in_array($skipReason, ['end_at_reached', 'max_runs_reached'], true)) {
                    $automation->forceFill([
                        'is_paused' => true,
                        'paused_at' => $automation->paused_at ?: $now,
                    ])->save();

                    Log::info('content_automation.completed', [
                        'automation_id' => (string) $automation->id,
                        'reason' => $skipReason,
                    ]);
                }

                continue;
            }

            $creditEvaluation = $creditWarnings->evaluateAutomation($automation);
            if (! (bool) ($creditEvaluation['can_run'] ?? false)) {
                $this->line(sprintf(
                    'Automation skipped: insufficient credits [%s] (%s)',
                    $automation->id,
                    (string) ($creditEvaluation['message'] ?? 'not enough credits')
                ));

                Log::warning('content_automation.scheduler_blocked_low_credits', [
                    'automation_id' => (string) $automation->id,
                    'automation_name' => (string) $automation->name,
                    'site_id' => (string) ($automation->client_site_id ?? ''),
                    'required_credits' => (int) ($creditEvaluation['required_credits'] ?? 0),
                    'available_credits' => (int) ($creditEvaluation['available_credits'] ?? 0),
                ]);

                continue;
            }

            RunContentAutomationJob::dispatch(
                automationId: (string) $automation->id,
                triggerType: 'scheduled',
                requestedByUserId: null,
            );

            Log::info('content_automation.scheduler_dispatched', [
                'automation_id' => (string) $automation->id,
                'automation_name' => (string) $automation->name,
                'site_id' => (string) ($automation->client_site_id ?? ''),
                'locale' => $automation->sourceLocale(),
                'next_run_at' => optional($automation->next_run_at)->toIso8601String(),
            ]);

            $dispatched++;
        }

        if ($dispatched === 0) {
            $this->info('No due content automations found.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Dispatched %d content automation run(s).', $dispatched));

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

    private function logSkip(ContentAutomation $automation, string $reason): void
    {
        $message = match ($reason) {
            'paused' => 'Automation skipped: paused',
            'end_at_reached' => 'Automation skipped: end_at reached',
            'max_runs_reached' => 'Automation skipped: max_runs reached',
            default => 'Automation skipped: inactive',
        };

        $this->line($message . ' [' . $automation->id . ']');

        Log::info('content_automation.skipped', [
            'automation_id' => (string) $automation->id,
            'automation_name' => (string) $automation->name,
            'site_id' => (string) ($automation->client_site_id ?? ''),
            'locale' => $automation->sourceLocale(),
            'reason' => $reason,
        ]);
    }
}
