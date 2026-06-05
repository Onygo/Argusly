<?php

use App\Support\AgenticMarketing\AgenticMarketingDedupe;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agentic_marketing_opportunities') || ! Schema::hasTable('agentic_marketing_actions')) {
            return;
        }

        Schema::table('agentic_marketing_opportunities', function (Blueprint $table): void {
            if (! Schema::hasColumn('agentic_marketing_opportunities', 'content_id')) {
                $table->uuid('content_id')->nullable()->after('objective_id')->index();
            }

            if (! Schema::hasColumn('agentic_marketing_opportunities', 'payload_hash')) {
                $table->char('payload_hash', 64)->nullable()->after('payload');
            }

            if (! Schema::hasColumn('agentic_marketing_opportunities', 'dedupe_hash')) {
                $table->char('dedupe_hash', 64)->nullable()->after('payload_hash');
            }

            if (! Schema::hasColumn('agentic_marketing_opportunities', 'open_dedupe_hash')) {
                $table->char('open_dedupe_hash', 64)->nullable()->after('dedupe_hash');
            }
        });

        Schema::table('agentic_marketing_actions', function (Blueprint $table): void {
            if (! Schema::hasColumn('agentic_marketing_actions', 'payload_hash')) {
                $table->char('payload_hash', 64)->nullable()->after('payload');
            }

            if (! Schema::hasColumn('agentic_marketing_actions', 'dedupe_hash')) {
                $table->char('dedupe_hash', 64)->nullable()->after('payload_hash');
            }

            if (! Schema::hasColumn('agentic_marketing_actions', 'open_dedupe_hash')) {
                $table->char('open_dedupe_hash', 64)->nullable()->after('dedupe_hash');
            }
        });

        $this->backfillOpportunities();
        $this->backfillActions();

        Schema::table('agentic_marketing_opportunities', function (Blueprint $table): void {
            if (! Schema::hasIndex('agentic_marketing_opportunities', 'agentic_opp_payload_hash_idx')) {
                $table->index('payload_hash', 'agentic_opp_payload_hash_idx');
            }

            if (! Schema::hasIndex('agentic_marketing_opportunities', 'agentic_opp_dedupe_hash_idx')) {
                $table->index('dedupe_hash', 'agentic_opp_dedupe_hash_idx');
            }

            if (! Schema::hasIndex('agentic_marketing_opportunities', 'agentic_opp_open_dedupe_unique')) {
                $table->unique(['objective_id', 'open_dedupe_hash'], 'agentic_opp_open_dedupe_unique');
            }
        });

        Schema::table('agentic_marketing_actions', function (Blueprint $table): void {
            if (! Schema::hasIndex('agentic_marketing_actions', 'agentic_action_payload_hash_idx')) {
                $table->index('payload_hash', 'agentic_action_payload_hash_idx');
            }

            if (! Schema::hasIndex('agentic_marketing_actions', 'agentic_action_dedupe_hash_idx')) {
                $table->index('dedupe_hash', 'agentic_action_dedupe_hash_idx');
            }

            if (! Schema::hasIndex('agentic_marketing_actions', 'agentic_action_open_dedupe_unique')) {
                $table->unique(['opportunity_id', 'open_dedupe_hash'], 'agentic_action_open_dedupe_unique');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('agentic_marketing_opportunities')) {
            Schema::table('agentic_marketing_opportunities', function (Blueprint $table): void {
                foreach (['agentic_opp_open_dedupe_unique', 'agentic_opp_payload_hash_idx', 'agentic_opp_dedupe_hash_idx'] as $index) {
                    if (Schema::hasIndex('agentic_marketing_opportunities', $index)) {
                        $table->dropIndex($index);
                    }
                }

                foreach (['payload_hash', 'dedupe_hash', 'open_dedupe_hash'] as $column) {
                    if (Schema::hasColumn('agentic_marketing_opportunities', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('agentic_marketing_actions')) {
            Schema::table('agentic_marketing_actions', function (Blueprint $table): void {
                foreach (['agentic_action_open_dedupe_unique', 'agentic_action_payload_hash_idx', 'agentic_action_dedupe_hash_idx'] as $index) {
                    if (Schema::hasIndex('agentic_marketing_actions', $index)) {
                        $table->dropIndex($index);
                    }
                }

                foreach (['payload_hash', 'dedupe_hash', 'open_dedupe_hash'] as $column) {
                    if (Schema::hasColumn('agentic_marketing_actions', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function backfillOpportunities(): void
    {
        $seenOpen = [];

        DB::table('agentic_marketing_opportunities')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->each(function (object $opportunity) use (&$seenOpen): void {
                $payload = $this->decodePayload($opportunity->payload ?? null);
                $contentId = $opportunity->content_id ?: $this->payloadText($payload, 'content_id');
                $payloadHash = AgenticMarketingDedupe::payloadHash($payload);
                $dedupeHash = AgenticMarketingDedupe::opportunityHash($contentId, $opportunity->type ?? null, $payloadHash);
                $openKey = ($opportunity->objective_id ?? '').'|'.$dedupeHash;
                $openDedupeHash = null;

                if (($opportunity->status ?? '') === 'open' && ! isset($seenOpen[$openKey])) {
                    $openDedupeHash = $dedupeHash;
                    $seenOpen[$openKey] = true;
                }

                DB::table('agentic_marketing_opportunities')
                    ->where('id', $opportunity->id)
                    ->update([
                        'content_id' => $contentId,
                        'payload_hash' => $payloadHash,
                        'dedupe_hash' => $dedupeHash,
                        'open_dedupe_hash' => $openDedupeHash,
                    ]);
            });
    }

    private function backfillActions(): void
    {
        $seenOpen = [];
        $openStatuses = ['proposed', 'approved', 'running'];

        DB::table('agentic_marketing_actions')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->each(function (object $action) use (&$seenOpen, $openStatuses): void {
                $payload = $this->decodePayload($action->payload ?? null);
                $payloadHash = AgenticMarketingDedupe::payloadHash($payload);
                $dedupeHash = AgenticMarketingDedupe::actionHash($action->action_type ?? null, $payloadHash);
                $openKey = ($action->opportunity_id ?? '').'|'.$dedupeHash;
                $openDedupeHash = null;

                if (in_array((string) ($action->status ?? ''), $openStatuses, true) && ! isset($seenOpen[$openKey])) {
                    $openDedupeHash = $dedupeHash;
                    $seenOpen[$openKey] = true;
                }

                DB::table('agentic_marketing_actions')
                    ->where('id', $action->id)
                    ->update([
                        'payload_hash' => $payloadHash,
                        'dedupe_hash' => $dedupeHash,
                        'open_dedupe_hash' => $openDedupeHash,
                    ]);
            });
    }

    private function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (! is_string($payload) || trim($payload) === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function payloadText(array $payload, string $key): ?string
    {
        $value = data_get($payload, $key);

        return is_scalar($value) && trim((string) $value) !== ''
            ? trim((string) $value)
            : null;
    }
};
