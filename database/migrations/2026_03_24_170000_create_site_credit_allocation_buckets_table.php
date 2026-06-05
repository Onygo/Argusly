<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('site_credit_allocation_buckets');

        Schema::create('site_credit_allocation_buckets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('site_credit_allocation_id');
            $table->uuid('workspace_credit_transaction_id')->nullable();
            $table->uuid('workspace_id');
            $table->uuid('client_site_id');
            $table->string('source', 32)->nullable();
            $table->integer('amount');
            $table->integer('remaining')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->string('reference_type', 120)->nullable();
            $table->uuid('reference_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['site_credit_allocation_id', 'remaining'], 'scab_allocation_remaining_idx');
            $table->index(['client_site_id', 'source', 'remaining'], 'scab_site_source_remaining_idx');
            $table->index(['workspace_credit_transaction_id'], 'scab_workspace_tx_idx');
            $table->foreign('site_credit_allocation_id', 'scab_allocation_fk')->references('id')->on('site_credit_allocations')->cascadeOnDelete();
            $table->foreign('workspace_credit_transaction_id', 'scab_workspace_tx_fk')->references('id')->on('workspace_credit_transactions')->nullOnDelete();
            $table->foreign('workspace_id', 'scab_workspace_fk')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id', 'scab_site_fk')->references('id')->on('client_sites')->cascadeOnDelete();
        });

        $this->backfillFromLegacyLedger();
    }

    public function down(): void
    {
        Schema::dropIfExists('site_credit_allocation_buckets');
    }

    private function backfillFromLegacyLedger(): void
    {
        DB::table('credit_ledger_entries')
            ->whereIn('source', ['included_plan', 'addon_pack'])
            ->where('remaining', '>', 0)
            ->whereNotNull('client_site_id')
            ->orderBy('created_at')
            ->chunkById(200, function ($entries): void {
                foreach ($entries as $entry) {
                    $allocationId = DB::table('site_credit_allocations')
                        ->where('client_site_id', $entry->client_site_id)
                        ->value('id');

                    $workspaceId = DB::table('site_credit_allocations')
                        ->where('client_site_id', $entry->client_site_id)
                        ->value('workspace_id');

                    if (! $allocationId || ! $workspaceId) {
                        continue;
                    }

                    $metadata = is_array($entry->meta) ? $entry->meta : json_decode((string) $entry->meta, true);
                    $workspaceTransactionId = data_get($metadata, 'workspace_credit_transaction_id');

                    DB::table('site_credit_allocation_buckets')->insert([
                        'id' => (string) Str::uuid(),
                        'site_credit_allocation_id' => $allocationId,
                        'workspace_credit_transaction_id' => $workspaceTransactionId,
                        'workspace_id' => $workspaceId,
                        'client_site_id' => $entry->client_site_id,
                        'source' => $entry->source,
                        'amount' => (int) $entry->amount,
                        'remaining' => (int) $entry->remaining,
                        'expires_at' => $entry->expires_at,
                        'reference_type' => $entry->source_type,
                        'reference_id' => $entry->source_id,
                        'metadata' => json_encode([
                            'backfilled_from_credit_ledger_entry_id' => $entry->id,
                            'legacy_model' => true,
                            'legacy_entry_type' => $entry->type,
                        ]),
                        'created_at' => $entry->created_at ?? now(),
                        'updated_at' => $entry->updated_at ?? now(),
                    ]);

                    if ($workspaceTransactionId) {
                        DB::table('workspace_credit_transactions')
                            ->where('id', $workspaceTransactionId)
                            ->update([
                                'remaining' => 0,
                                'updated_at' => now(),
                            ]);
                    }
                }
            }, 'id');
    }
};
