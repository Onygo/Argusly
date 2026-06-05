<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (! Schema::hasColumn('plans', 'interval')) {
                $table->string('interval', 16)->default('month')->after('name');
            }

            if (! Schema::hasColumn('plans', 'price_cents')) {
                $table->unsignedInteger('price_cents')->nullable()->after('monthly_price_cents');
            }

            if (! Schema::hasColumn('plans', 'included_credits_per_interval')) {
                $table->unsignedInteger('included_credits_per_interval')->nullable()->after('included_credits');
            }

            if (! Schema::hasColumn('plans', 'seat_limit')) {
                $table->unsignedInteger('seat_limit')->default(1)->after('limits');
            }
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('subscriptions', 'organization_id')) {
                $table->unsignedBigInteger('organization_id')->nullable()->after('id');
            }

            if (! Schema::hasColumn('subscriptions', 'interval')) {
                $table->string('interval', 16)->default('month')->after('plan_id');
            }

            if (! Schema::hasColumn('subscriptions', 'price_cents')) {
                $table->unsignedInteger('price_cents')->nullable()->after('interval');
            }

            if (! Schema::hasColumn('subscriptions', 'currency')) {
                $table->string('currency', 8)->default('EUR')->after('price_cents');
            }

            if (! Schema::hasColumn('subscriptions', 'included_credits_per_interval')) {
                $table->unsignedInteger('included_credits_per_interval')->nullable()->after('currency');
            }

            if (! Schema::hasColumn('subscriptions', 'seat_limit')) {
                $table->unsignedInteger('seat_limit')->nullable()->after('included_credits_per_interval');
            }

            if (! Schema::hasColumn('subscriptions', 'provider_mandate_id')) {
                $table->string('provider_mandate_id', 128)->nullable()->after('provider_customer_id');
            }

            if (! Schema::hasColumn('subscriptions', 'provider_payment_id')) {
                $table->string('provider_payment_id', 128)->nullable()->after('provider_subscription_id');
            }
        });

        Schema::table('credit_packs', function (Blueprint $table) {
            if (! Schema::hasColumn('credit_packs', 'expires_in_months')) {
                $table->unsignedInteger('expires_in_months')->nullable()->after('currency');
            }

            if (! Schema::hasColumn('credit_packs', 'never_expires')) {
                $table->boolean('never_expires')->default(false)->after('expires_in_months');
            }
        });

        Schema::table('organizations', function (Blueprint $table) {
            if (! Schema::hasColumn('organizations', 'primary_user_id')) {
                $table->foreignId('primary_user_id')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('organizations', 'active_subscription_id')) {
                $table->uuid('active_subscription_id')->nullable()->after('primary_user_id');
            }

            if (! Schema::hasColumn('organizations', 'billing_company_name')) {
                $table->string('billing_company_name')->nullable()->after('webhook_url');
            }

            if (! Schema::hasColumn('organizations', 'billing_address_line1')) {
                $table->string('billing_address_line1')->nullable()->after('billing_company_name');
            }

            if (! Schema::hasColumn('organizations', 'billing_address_line2')) {
                $table->string('billing_address_line2')->nullable()->after('billing_address_line1');
            }

            if (! Schema::hasColumn('organizations', 'billing_postal_code')) {
                $table->string('billing_postal_code', 64)->nullable()->after('billing_address_line2');
            }

            if (! Schema::hasColumn('organizations', 'billing_city')) {
                $table->string('billing_city', 128)->nullable()->after('billing_postal_code');
            }

            if (! Schema::hasColumn('organizations', 'billing_country_code')) {
                $table->string('billing_country_code', 2)->nullable()->after('billing_city');
            }

            if (! Schema::hasColumn('organizations', 'billing_vat_number')) {
                $table->string('billing_vat_number', 64)->nullable()->after('billing_country_code');
            }

            if (! Schema::hasColumn('organizations', 'billing_kvk_number')) {
                $table->string('billing_kvk_number', 64)->nullable()->after('billing_vat_number');
            }
        });

        DB::table('plans')->orderBy('created_at')->chunkById(100, function ($plans): void {
            foreach ($plans as $plan) {
                $limits = is_array($plan->limits) ? $plan->limits : json_decode((string) $plan->limits, true);

                DB::table('plans')
                    ->where('id', $plan->id)
                    ->update([
                        'price_cents' => $plan->price_cents ?? $plan->monthly_price_cents,
                        'included_credits_per_interval' => $plan->included_credits_per_interval ?? $plan->included_credits,
                        'seat_limit' => $plan->seat_limit ?? max(1, (int) data_get($limits, 'users', 1)),
                        'interval' => $plan->interval ?? 'month',
                    ]);
            }
        }, 'id');

        DB::table('credit_packs')
            ->whereNull('expires_in_months')
            ->update([
                'expires_in_months' => 12,
                'never_expires' => false,
            ]);

        DB::table('subscriptions')->orderBy('id')->chunkById(100, function ($subscriptions): void {
            foreach ($subscriptions as $subscription) {
                $organizationId = DB::table('client_sites as cs')
                    ->join('workspaces as w', 'w.id', '=', 'cs.workspace_id')
                    ->where('cs.id', $subscription->client_site_id)
                    ->value('w.organization_id');

                $plan = DB::table('plans')->where('id', $subscription->plan_id)->first();

                DB::table('subscriptions')
                    ->where('id', $subscription->id)
                    ->update([
                        'organization_id' => $subscription->organization_id ?? $organizationId,
                        'interval' => $subscription->interval ?? ($plan->interval ?? 'month'),
                        'price_cents' => $subscription->price_cents ?? ($plan->price_cents ?? $plan->monthly_price_cents ?? null),
                        'currency' => $subscription->currency ?? ($plan->currency ?? 'EUR'),
                        'included_credits_per_interval' => $subscription->included_credits_per_interval ?? ($plan->included_credits_per_interval ?? $plan->included_credits ?? 0),
                        'seat_limit' => $subscription->seat_limit ?? ($plan->seat_limit ?? 1),
                    ]);
            }
        }, 'id');

        DB::table('organizations')->orderBy('id')->chunkById(100, function ($organizations): void {
            foreach ($organizations as $organization) {
                $primaryUserId = DB::table('users')
                    ->where('organization_id', $organization->id)
                    ->orderByRaw("CASE WHEN role = 'owner' THEN 0 WHEN role = 'admin' THEN 1 ELSE 2 END")
                    ->orderBy('created_at')
                    ->value('id');

                $activeSubscriptionId = DB::table('subscriptions')
                    ->where('organization_id', $organization->id)
                    ->whereIn('status', ['active', 'trialing'])
                    ->orderByDesc('updated_at')
                    ->value('id');

                DB::table('organizations')
                    ->where('id', $organization->id)
                    ->update([
                        'primary_user_id' => $organization->primary_user_id ?? $primaryUserId,
                        'active_subscription_id' => $organization->active_subscription_id ?? $activeSubscriptionId,
                        'billing_company_name' => $organization->billing_company_name ?? $organization->name,
                    ]);
            }
        }, 'id');

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->index(['organization_id', 'status'], 'subs_org_status_idx');
            $table->index(['organization_id', 'current_period_end'], 'subs_org_period_end_idx');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->foreign('active_subscription_id')
                ->references('id')
                ->on('subscriptions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (Schema::hasColumn('organizations', 'active_subscription_id')) {
                $table->dropForeign(['active_subscription_id']);
            }

            $dropColumns = [
                'active_subscription_id',
                'primary_user_id',
                'billing_company_name',
                'billing_address_line1',
                'billing_address_line2',
                'billing_postal_code',
                'billing_city',
                'billing_country_code',
                'billing_vat_number',
                'billing_kvk_number',
            ];

            foreach ($dropColumns as $column) {
                if (Schema::hasColumn('organizations', $column)) {
                    if ($column === 'primary_user_id') {
                        $table->dropConstrainedForeignId('primary_user_id');
                    } else {
                        $table->dropColumn($column);
                    }
                }
            }
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('subscriptions', 'organization_id')) {
                $table->dropForeign(['organization_id']);
                $table->dropIndex('subs_org_status_idx');
                $table->dropIndex('subs_org_period_end_idx');
                $table->dropColumn('organization_id');
            }

            foreach (['interval', 'price_cents', 'currency', 'included_credits_per_interval', 'seat_limit', 'provider_mandate_id', 'provider_payment_id'] as $column) {
                if (Schema::hasColumn('subscriptions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('plans', function (Blueprint $table) {
            foreach (['interval', 'price_cents', 'included_credits_per_interval', 'seat_limit'] as $column) {
                if (Schema::hasColumn('plans', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('credit_packs', function (Blueprint $table) {
            foreach (['expires_in_months', 'never_expires'] as $column) {
                if (Schema::hasColumn('credit_packs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
