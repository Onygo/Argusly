<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            if (! Schema::hasColumn('plans', 'article_estimate_min')) {
                $table->unsignedInteger('article_estimate_min')->nullable()->after('included_credits_per_interval');
            }

            if (! Schema::hasColumn('plans', 'article_estimate_max')) {
                $table->unsignedInteger('article_estimate_max')->nullable()->after('article_estimate_min');
            }

            if (! Schema::hasColumn('plans', 'workspace_limit')) {
                $table->unsignedInteger('workspace_limit')->nullable()->after('limits');
            }

            if (! Schema::hasColumn('plans', 'user_limit')) {
                $table->unsignedInteger('user_limit')->nullable()->after('workspace_limit');
            }
        });

        Schema::table('credit_pack_purchases', function (Blueprint $table): void {
            if (! Schema::hasColumn('credit_pack_purchases', 'purchased_credit_expires_at')) {
                $table->timestamp('purchased_credit_expires_at')->nullable()->after('paid_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('credit_pack_purchases', function (Blueprint $table): void {
            if (Schema::hasColumn('credit_pack_purchases', 'purchased_credit_expires_at')) {
                $table->dropColumn('purchased_credit_expires_at');
            }
        });

        Schema::table('plans', function (Blueprint $table): void {
            foreach (['article_estimate_min', 'article_estimate_max', 'workspace_limit', 'user_limit'] as $column) {
                if (Schema::hasColumn('plans', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
