<?php

namespace App\Actions\Briefs;

use App\Jobs\Briefs\EnhanceBriefJob;
use App\Models\Brief;
use App\Models\User;
use App\Services\Briefs\BriefGapAnalyzer;
use App\Services\Briefs\BriefIntelligenceService;
use App\Services\Entitlements\FeatureGate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class EnhanceBriefAction
{
    public function __construct(
        private readonly FeatureGate $featureGate,
        private readonly BriefIntelligenceService $intelligenceService,
        private readonly BriefGapAnalyzer $gapAnalyzer,
    ) {
    }

    public function queue(Brief $brief, User $user, bool $force = false): void
    {
        $brief->loadMissing('clientSite.workspace');

        if (! $brief->clientSite?->workspace) {
            throw new RuntimeException('Workspace context is missing for this brief.');
        }

        $this->assertFeatureEnabled($brief);

        $runKey = (string) Str::uuid();

        DB::transaction(function () use ($brief, $user, $force, $runKey): void {
            $locked = Brief::query()->whereKey($brief->id)->lockForUpdate()->firstOrFail();
            $refs = is_array($locked->client_refs) ? $locked->client_refs : [];
            $intelligence = is_array($refs['brief_intelligence'] ?? null) ? $refs['brief_intelligence'] : [];

            $intelligence['runtime'] = array_replace_recursive((array) ($intelligence['runtime'] ?? []), [
                'queued_at' => now()->toIso8601String(),
                'queued_by' => $user->id,
                'run_key' => $runKey,
                'force' => $force,
                'status' => 'queued',
                'failure_reason' => null,
            ]);

            $refs['brief_intelligence'] = $intelligence;
            $locked->client_refs = $refs;
            $locked->save();
        });

        EnhanceBriefJob::dispatch((string) $brief->id, $runKey, $force, (int) $user->id)
            ->onQueue((string) config('brief_intelligence.queue', 'brief-intelligence'))
            ->afterCommit();
    }

    /**
     * @return array{brief:Brief,analysis:array<string,mixed>,suggestions_created:int,intelligence_summary:string,input_hash:string,linked_research:array<string,mixed>|null,llm:array<string,string>}
     */
    public function execute(Brief $brief, User $user, bool $force = false): array
    {
        $brief->loadMissing('clientSite.workspace');

        if (! $brief->clientSite?->workspace) {
            throw new RuntimeException('Workspace context is missing for this brief.');
        }

        $this->assertFeatureEnabled($brief);

        $analysis = $this->gapAnalyzer->analyze($brief);
        $result = $this->intelligenceService->generateSuggestions($brief, $force);

        $refs = is_array($brief->client_refs) ? $brief->client_refs : [];
        $intelligence = is_array($refs['brief_intelligence'] ?? null) ? $refs['brief_intelligence'] : [];
        $intelligence = array_replace_recursive($intelligence, [
            'completeness' => $analysis,
            'intelligence_summary' => (string) ($result['intelligence_summary'] ?? ''),
            'linked_research' => $result['linked_research'] ?? null,
            'last_input_hash' => (string) ($result['input_hash'] ?? ''),
            'last_run' => [
                'status' => 'succeeded',
                'completed_at' => now()->toIso8601String(),
                'run_by' => $user->id,
                'suggestions_created' => (int) ($result['suggestions_created'] ?? 0),
                'force' => $force,
                'llm' => $result['llm'] ?? [
                    'provider' => '',
                    'model' => '',
                    'request_id' => '',
                ],
            ],
            'context_snapshot' => $result['context_snapshot'] ?? [],
        ]);

        $refs['brief_intelligence'] = $intelligence;
        $brief->client_refs = $refs;
        $brief->save();

        return [
            'brief' => $brief->fresh(),
            'analysis' => $analysis,
            'suggestions_created' => (int) ($result['suggestions_created'] ?? 0),
            'intelligence_summary' => (string) ($result['intelligence_summary'] ?? ''),
            'input_hash' => (string) ($result['input_hash'] ?? ''),
            'linked_research' => $result['linked_research'] ?? null,
            'llm' => $result['llm'] ?? [
                'provider' => '',
                'model' => '',
                'request_id' => '',
            ],
        ];
    }

    private function assertFeatureEnabled(Brief $brief): void
    {
        if (! $this->toBool($this->featureGate->value($brief->clientSite?->workspace, 'brief_intelligence_enabled', false), false)) {
            throw new AuthorizationException('Brief intelligence is not enabled for this workspace.');
        }
    }

    private function toBool(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        return ! in_array(strtolower(trim((string) $value)), ['', '0', 'false', 'off', 'no'], true);
    }
}
