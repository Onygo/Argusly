<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('subscriptions', 'pending_plan_id')) {
                $table->uuid('pending_plan_id')->nullable()->after('plan_id');
            }

            if (! Schema::hasColumn('subscriptions', 'next_payment_at')) {
                $table->timestamp('next_payment_at')->nullable()->after('current_period_end');
            }

            if (! Schema::hasColumn('subscriptions', 'grace_period_ends_at')) {
                $table->timestamp('grace_period_ends_at')->nullable()->after('next_payment_at');
            }

            if (! Schema::hasColumn('subscriptions', 'suspended_at')) {
                $table->timestamp('suspended_at')->nullable()->after('canceled_at');
            }

            if (! Schema::hasColumn('subscriptions', 'status_reason')) {
                $table->string('status_reason', 255)->nullable()->after('status');
            }

            if (! Schema::hasColumn('subscriptions', 'mandate_last_checked_at')) {
                $table->timestamp('mandate_last_checked_at')->nullable()->after('provider_mandate_id');
            }
        });

        if (! $this->indexExists('subscriptions', 'subs_pending_plan_idx')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->index('pending_plan_id', 'subs_pending_plan_idx');
            });
        }

        if (! $this->indexExists('subscriptions', 'subs_next_payment_idx')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->index('next_payment_at', 'subs_next_payment_idx');
            });
        }

        if (! $this->indexExists('subscriptions', 'subs_grace_idx')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->index('grace_period_ends_at', 'subs_grace_idx');
            });
        }

        if (! $this->foreignKeyExists('subscriptions', 'subscriptions_pending_plan_id_foreign')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->foreign('pending_plan_id')->references('id')->on('plans')->nullOnDelete();
            });
        }

        Schema::create('billing_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 128)->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('subscription_plan_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('subscription_id');
            $table->unsignedBigInteger('organization_id');
            $table->uuid('from_plan_id');
            $table->uuid('to_plan_id');
            $table->string('strategy', 32); // immediate_proration | next_period
            $table->string('status', 32)->default('pending'); // pending|applied|blocked|failed
            $table->unsignedInteger('proration_amount_cents')->default(0);
            $table->string('currency', 8)->default('EUR');
            $table->uuid('payment_intent_id')->nullable();
            $table->uuid('invoice_id')->nullable();
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->string('blocked_reason', 255)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'status']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['strategy', 'status']);

            $table->foreign('subscription_id')->references('id')->on('subscriptions')->cascadeOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('from_plan_id')->references('id')->on('plans')->cascadeOnDelete();
            $table->foreign('to_plan_id')->references('id')->on('plans')->cascadeOnDelete();
            $table->foreign('payment_intent_id')->references('id')->on('payment_intents')->nullOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
        });

        $defaults = [
            'plan_change.defaults' => [
                'upgrade_strategy' => 'next_period',
                'downgrade_strategy' => 'next_period',
                'allow_immediate_downgrade' => false,
            ],
            'dunning.defaults' => [
                'grace_days' => 7,
                'suspend_after_grace' => true,
            ],
            'credits.defaults' => [
                'included_rollover_enabled' => false,
                'consumption_order' => 'included_first_then_addon',
            ],
            'mollie.recurring' => [
                'mandate_retry_minutes' => 15,
                'mandate_retry_attempts' => 24,
                'recurring_method_allowlist' => ['creditcard', 'directdebit', 'paypal', 'bancontact'],
            ],
        ];

        foreach ($defaults as $key => $value) {
            DB::table('billing_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()]
            );
        }

        DB::table('subscriptions')->orderBy('id')->chunkById(200, function ($subs): void {
            foreach ($subs as $sub) {
                $periodEnd = $sub->current_period_end;
                $nextPaymentAt = $sub->next_payment_at;

                if (! $nextPaymentAt && $periodEnd) {
                    $nextPaymentAt = $periodEnd;
                }

                $normalizedStatus = in_array($sub->status, ['active', 'trialing', 'past_due', 'canceled', 'pending_mandate', 'suspended'], true)
                    ? $sub->status
                    : 'active';

                DB::table('subscriptions')->where('id', $sub->id)->update([
                    'next_payment_at' => $nextPaymentAt,
                    'status' => $normalizedStatus,
                ]);
            }
        }, 'id');
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plan_changes');
        Schema::dropIfExists('billing_settings');

        if ($this->foreignKeyExists('subscriptions', 'subscriptions_pending_plan_id_foreign')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropForeign(['pending_plan_id']);
            });
        }

        foreach (['subs_pending_plan_idx', 'subs_next_payment_idx', 'subs_grace_idx'] as $indexName) {
            if ($this->indexExists('subscriptions', $indexName)) {
                Schema::table('subscriptions', function (Blueprint $table) use ($indexName) {
                    $table->dropIndex($indexName);
                });
            }
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            foreach (['pending_plan_id', 'next_payment_at', 'grace_period_ends_at', 'suspended_at', 'status_reason', 'mandate_last_checked_at'] as $column) {
                if (Schema::hasColumn('subscriptions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            $database = DB::getDatabaseName();
            $row = DB::selectOne(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
                [$database, $table, $indexName]
            );

            return $row !== null;
        }

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                if (($row->name ?? null) === $indexName) {
                    return true;
                }
            }
        }

        return false;
    }

    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            $database = DB::getDatabaseName();
            $row = DB::selectOne(
                'SELECT 1 FROM information_schema.table_constraints WHERE table_schema = ? AND table_name = ? AND constraint_name = ? AND constraint_type = ? LIMIT 1',
                [$database, $table, $constraintName, 'FOREIGN KEY']
            );

            return $row !== null;
        }

        return false;
    }
};
