<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Billing\BillingAuditService;
use Illuminate\Console\Command;

class BillingAuditCommand extends Command
{
    protected $signature = 'billing:audit {--org-id=} {--limit=100}';

    protected $description = 'Audit billing claims, credit expiry enforcement, workspace sharing, and cached balance integrity.';

    public function handle(BillingAuditService $audit): int
    {
        $orgId = trim((string) $this->option('org-id'));

        if ($orgId !== '') {
            $organization = Organization::query()->find($orgId);
            if (! $organization) {
                $this->error('Organization not found.');

                return self::FAILURE;
            }

            $result = $audit->auditOrganization($organization);
            $this->renderOrganizationResult($result);

            return self::SUCCESS;
        }

        $result = $audit->auditPlatform((int) $this->option('limit'));
        $this->line('Scanned: ' . (int) $result['scanned']);
        $this->line('Critical: ' . (int) $result['critical']);
        $this->line('Warning: ' . (int) $result['warning']);
        $this->line('OK: ' . (int) $result['ok']);

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function renderOrganizationResult(array $result): void
    {
        $this->line('Organization: ' . (string) ($result['organization_id'] ?? 'n/a'));
        $this->line('Health: ' . (string) ($result['health'] ?? 'unknown'));
        $this->line('Claims:');
        foreach ((array) ($result['claim_statuses'] ?? []) as $claim => $status) {
            $this->line('  - ' . $claim . ': ' . $status);
        }

        $issues = (array) ($result['issues'] ?? []);
        if ($issues === []) {
            $this->info('No billing issues detected.');

            return;
        }

        $this->warn('Issues:');
        foreach ($issues as $issue) {
            $this->line(sprintf(
                '  - [%s] %s (%d)',
                strtoupper((string) ($issue['severity'] ?? 'info')),
                (string) ($issue['message'] ?? ''),
                (int) ($issue['count'] ?? 0)
            ));
        }
    }
}
