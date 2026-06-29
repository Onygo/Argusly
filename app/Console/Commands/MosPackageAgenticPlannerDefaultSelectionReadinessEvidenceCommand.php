<?php

namespace App\Console\Commands;

use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionCiEvidencePackageService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionOperatorSignOffRunbookService;
use Illuminate\Console\Command;

class MosPackageAgenticPlannerDefaultSelectionReadinessEvidenceCommand extends Command
{
    protected $signature = 'mos:package-agentic-planner-default-selection-readiness-evidence
        {--workspace= : Required workspace id}
        {--objectives= : Comma-separated AgenticMarketingObjective ids}
        {--site= : Limit to one client site id}
        {--detector= : Limit inspected rows by detector key}
        {--limit= : Required candidate limit per objective}
        {--ack-metadata-only-review : Acknowledge operator review of metadata_only_ok rows for the Phase 4D inspection}
        {--ack-runtime-switch-contract : Acknowledge the Phase 3Y runtime switch skeleton contract for the Phase 4D inspection}
        {--ack-operator-signoff : Acknowledge explicit operator review/sign-off intent as review evidence only}
        {--require-real-scope : Require matching workspace and objective records in the database}
        {--ci : Exit non-zero when the package is blocked}';

    protected $description = 'Phase 4F CI/review evidence package for MOS Agentic planner default selection.';

    public function handle(AgenticPlannerDefaultSelectionCiEvidencePackageService $packager): int
    {
        $workspace = trim((string) $this->option('workspace'));
        $objectives = trim((string) $this->option('objectives'));
        $limit = trim((string) $this->option('limit'));

        if ($workspace === '') {
            $this->components->error('The --workspace option is required.');

            return self::INVALID;
        }

        if ($objectives === '') {
            $this->components->error('The --objectives option is required.');

            return self::INVALID;
        }

        if ($limit === '') {
            $this->components->error('The --limit option is required.');

            return self::INVALID;
        }

        $this->components->info('Phase 4F Agentic planner default-selection CI/review evidence package.');
        $this->components->warn('Packaging evidence only: Phase 4F does not change runtime behavior, activate planner switching, consume activation flags, replace legacy output, mutate runtime records, write audits, migrate ownership, sync lifecycle, add rollout, or dispatch jobs.');

        $package = $packager->package([
            'workspace' => $workspace,
            'objectives' => $objectives,
            'site' => $this->option('site'),
            'detector' => $this->option('detector'),
            'limit' => max(1, (int) $limit),
            'ack_metadata_only_review' => (bool) $this->option('ack-metadata-only-review'),
            'ack_runtime_switch_contract' => (bool) $this->option('ack-runtime-switch-contract'),
            'ack_operator_signoff' => (bool) $this->option('ack-operator-signoff'),
            'require_real_scope' => (bool) $this->option('require-real-scope'),
        ]);

        $this->renderPackage($package);

        if ((bool) $this->option('ci') && ($package['package_status'] ?? null) !== AgenticPlannerDefaultSelectionCiEvidencePackageService::STATUS_READY) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $package
     */
    private function renderPackage(array $package): void
    {
        $this->newLine();
        $this->components->info('Package status');
        $this->table(
            ['package status', 'evidence status', 'sign-off status', 'workspace', 'objectives', 'package checksum'],
            [[
                (string) ($package['package_status'] ?? AgenticPlannerDefaultSelectionCiEvidencePackageService::STATUS_BLOCKED),
                (string) ($package['phase_4d_final_evidence_status'] ?? AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService::STATUS_BLOCKED),
                (string) ($package['phase_4e_signoff_readiness'] ?? AgenticPlannerDefaultSelectionOperatorSignOffRunbookService::STATUS_BLOCKED),
                (string) ($package['workspace_id'] ?? 'none'),
                count((array) ($package['objective_ids'] ?? [])),
                (string) ($package['package_checksum'] ?? 'missing'),
            ]]
        );

        $this->components->info('Exact scope summary');
        $this->table(
            ['workspace', 'objective ids', 'site', 'detector', 'real scope', 'wildcard inferred'],
            [[
                (string) data_get($package, 'scope.workspace_id', 'none'),
                $this->sampleLine((array) data_get($package, 'scope.objective_ids', [])),
                data_get($package, 'scope.site_id') ?: 'none',
                data_get($package, 'scope.detector') ?: 'none',
                $this->boolLine((bool) data_get($package, 'scope.real_scope_detected', false)),
                $this->boolLine((bool) data_get($package, 'scope.wildcard_scope_inferred', false)),
            ]]
        );

        $this->components->info('Rollback confirmation');
        $this->table(
            ['rollback mode', 'legacy first', 'legacy output authoritative', 'metadata required for rollback'],
            [[
                (string) data_get($package, 'rollback_confirmation.rollback_mode', 'legacy_first'),
                $this->boolLine((bool) data_get($package, 'rollback_confirmation.legacy_first', true)),
                $this->boolLine((bool) data_get($package, 'rollback_confirmation.legacy_output_remains_authoritative', true)),
                $this->boolLine((bool) data_get($package, 'rollback_confirmation.additive_metadata_required_for_rollback', false)),
            ]]
        );

        $this->components->info('Non-activation confirmations');
        $this->line('selected planner remains: '.data_get($package, 'non_activation_confirmations.selected_planner_remains', 'legacy'));
        $this->line('activation_flag_consumed_for_switching: '.$this->boolLine((bool) data_get($package, 'non_activation_confirmations.activation_flag_consumed_for_switching', false)));
        $this->line('runtime_behavior_changed: '.$this->boolLine((bool) ($package['runtime_behavior_changed'] ?? true)));
        $this->line('legacy_planner_output_replaced: '.$this->boolLine((bool) data_get($package, 'non_activation_confirmations.legacy_planner_output_replaced', false)));
        $this->line('agentic_marketing_action_created_or_mutated: '.$this->boolLine((bool) data_get($package, 'non_activation_confirmations.agentic_marketing_action_created_or_mutated', false)));
        $this->line('job_dispatched: '.$this->boolLine((bool) data_get($package, 'non_activation_confirmations.job_dispatched', false)));

        $this->components->info('Blocked remediation guidance');
        $guidance = collect((array) ($package['remediation_guidance'] ?? []))
            ->map(fn (array $row): array => [
                (string) ($row['reason'] ?? 'unknown'),
                (string) ($row['guidance'] ?? 'Review Phase 4D and Phase 4E evidence and remediate upstream.'),
            ])
            ->all();

        if ($guidance === []) {
            $this->line('none');
        } else {
            $this->table(['blocked reason', 'remediation guidance'], $guidance);
        }

        $this->line('package checksum: '.($package['package_checksum'] ?? 'missing'));
        $this->line('package checksum scope: '.($package['package_checksum_scope'] ?? 'canonical_package_excluding_generated_at_and_checksum_fields'));
        $this->line('generated_at: '.($package['generated_at'] ?? 'missing'));
        $this->line('final package status: '.($package['package_status'] ?? AgenticPlannerDefaultSelectionCiEvidencePackageService::STATUS_BLOCKED));
    }

    /**
     * @param  array<int,mixed>  $values
     */
    private function sampleLine(array $values): string
    {
        $values = collect($values)
            ->map(fn (mixed $value): string => is_scalar($value) ? (string) $value : (json_encode($value, JSON_UNESCAPED_SLASHES) ?: '{}'))
            ->filter()
            ->values()
            ->all();

        return $values === [] ? 'none' : implode(' | ', $values);
    }

    private function boolLine(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }
}
