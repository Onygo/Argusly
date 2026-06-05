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
        Schema::create('plan_features', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('plan_id');
            $table->string('feature_key', 120);
            $table->string('value_type', 16)->default('bool'); // bool|int|string|json
            $table->boolean('value_bool')->nullable();
            $table->integer('value_int')->nullable();
            $table->string('value_string', 255)->nullable();
            $table->json('value_json')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'feature_key']);
            $table->index(['feature_key']);
            $table->foreign('plan_id')->references('id')->on('plans')->cascadeOnDelete();
        });

        Schema::create('workspace_entitlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->uuid('subscription_id')->nullable();
            $table->uuid('plan_id')->nullable();
            $table->string('feature_key', 120);
            $table->string('value_type', 16)->default('bool'); // bool|int|string|json
            $table->boolean('value_bool')->nullable();
            $table->integer('value_int')->nullable();
            $table->string('value_string', 255)->nullable();
            $table->json('value_json')->nullable();
            $table->string('source', 32)->default('plan'); // plan|manual|migration
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('refreshed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'feature_key']);
            $table->index(['organization_id', 'feature_key']);
            $table->index(['subscription_id']);

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
            $table->foreign('plan_id')->references('id')->on('plans')->nullOnDelete();
        });

        Schema::create('credit_wallet_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('credit_ledger_entry_id')->unique();
            $table->uuid('credit_wallet_id');
            $table->uuid('client_site_id')->nullable();
            $table->uuid('workspace_id')->nullable();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('type', 32);
            $table->string('source', 32)->nullable();
            $table->integer('amount');
            $table->integer('remaining')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->string('source_type', 64)->nullable();
            $table->uuid('source_id')->nullable();
            $table->uuid('purchase_payment_id')->nullable();
            $table->uuid('consumed_from_entry_id')->nullable();
            $table->uuid('brief_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->json('meta')->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->timestamps();

            $table->index(['credit_wallet_id', 'created_at']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['workspace_id', 'created_at']);
            $table->index(['type']);
            $table->index(['source']);

            $table->foreign('credit_ledger_entry_id')->references('id')->on('credit_ledger_entries')->cascadeOnDelete();
            $table->foreign('credit_wallet_id')->references('id')->on('credit_wallets')->cascadeOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::table('plans', function (Blueprint $table) {
            if (! Schema::hasColumn('plans', 'vat_included')) {
                $table->boolean('vat_included')->default(true)->after('currency');
            }
        });

        Schema::table('credit_packs', function (Blueprint $table) {
            if (! Schema::hasColumn('credit_packs', 'vat_included')) {
                $table->boolean('vat_included')->default(true)->after('currency');
            }
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('subscriptions', 'workspace_id')) {
                $table->uuid('workspace_id')->nullable()->after('organization_id');
                $table->index(['workspace_id', 'status'], 'subs_workspace_status_idx');
                $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
            }
        });

        Schema::table('credit_wallets', function (Blueprint $table) {
            if (! Schema::hasColumn('credit_wallets', 'workspace_id')) {
                $table->uuid('workspace_id')->nullable()->after('client_site_id');
                $table->index(['workspace_id'], 'credit_wallets_workspace_idx');
                $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
            }
        });

        DB::table('subscriptions')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                $workspaceId = DB::table('client_sites')
                    ->where('id', $row->client_site_id)
                    ->value('workspace_id');

                DB::table('subscriptions')
                    ->where('id', $row->id)
                    ->update(['workspace_id' => $workspaceId]);
            }
        }, 'id');

        DB::table('credit_wallets')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                $workspaceId = DB::table('client_sites')
                    ->where('id', $row->client_site_id)
                    ->value('workspace_id');

                DB::table('credit_wallets')
                    ->where('id', $row->id)
                    ->update(['workspace_id' => $workspaceId]);
            }
        }, 'id');

        DB::table('credit_ledger_entries')->orderBy('id')->chunkById(200, function ($entries): void {
            foreach ($entries as $entry) {
                $exists = DB::table('credit_wallet_transactions')
                    ->where('credit_ledger_entry_id', $entry->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $workspaceId = DB::table('credit_wallets')
                    ->where('id', $entry->credit_wallet_id)
                    ->value('workspace_id');

                DB::table('credit_wallet_transactions')->insert([
                    'id' => (string) Str::uuid(),
                    'credit_ledger_entry_id' => $entry->id,
                    'credit_wallet_id' => $entry->credit_wallet_id,
                    'client_site_id' => $entry->client_site_id,
                    'workspace_id' => $workspaceId,
                    'organization_id' => $entry->organization_id,
                    'type' => $entry->type,
                    'source' => $entry->source,
                    'amount' => (int) $entry->amount,
                    'remaining' => (int) $entry->remaining,
                    'expires_at' => $entry->expires_at,
                    'period_start' => $entry->period_start,
                    'period_end' => $entry->period_end,
                    'source_type' => $entry->source_type,
                    'source_id' => $entry->source_id,
                    'purchase_payment_id' => $entry->purchase_payment_id,
                    'consumed_from_entry_id' => $entry->consumed_from_entry_id,
                    'brief_id' => $entry->brief_id,
                    'user_id' => $entry->user_id,
                    'meta' => $entry->meta,
                    'idempotency_key' => $entry->idempotency_key,
                    'created_at' => $entry->created_at,
                    'updated_at' => $entry->updated_at,
                ]);
            }
        }, 'id');
    }

    public function down(): void
    {
        Schema::table('credit_wallets', function (Blueprint $table) {
            if (Schema::hasColumn('credit_wallets', 'workspace_id')) {
                $table->dropForeign(['workspace_id']);
                $table->dropIndex('credit_wallets_workspace_idx');
                $table->dropColumn('workspace_id');
            }
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('subscriptions', 'workspace_id')) {
                $table->dropForeign(['workspace_id']);
                $table->dropIndex('subs_workspace_status_idx');
                $table->dropColumn('workspace_id');
            }
        });

        Schema::table('credit_packs', function (Blueprint $table) {
            if (Schema::hasColumn('credit_packs', 'vat_included')) {
                $table->dropColumn('vat_included');
            }
        });

        Schema::table('plans', function (Blueprint $table) {
            if (Schema::hasColumn('plans', 'vat_included')) {
                $table->dropColumn('vat_included');
            }
        });

        Schema::dropIfExists('credit_wallet_transactions');
        Schema::dropIfExists('workspace_entitlements');
        Schema::dropIfExists('plan_features');
    }
};
