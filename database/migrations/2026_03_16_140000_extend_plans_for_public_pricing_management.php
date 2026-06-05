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
            if (! Schema::hasColumn('plans', 'price_yearly_cents')) {
                $table->unsignedInteger('price_yearly_cents')->nullable()->after('price_monthly_cents');
            }

            if (! Schema::hasColumn('plans', 'is_public')) {
                $table->boolean('is_public')->default(true)->after('is_active');
            }

            if (! Schema::hasColumn('plans', 'billing_type')) {
                $table->string('billing_type', 32)->default('fixed')->after('is_public');
            }

            if (! Schema::hasColumn('plans', 'is_featured')) {
                $table->boolean('is_featured')->default(false)->after('billing_type');
            }

            if (! Schema::hasColumn('plans', 'badge')) {
                $table->string('badge', 120)->nullable()->after('sort_order');
            }
        });

        DB::table('plans')
            ->select(['id', 'slug', 'key', 'is_popular', 'is_active'])
            ->orderBy('created_at')
            ->chunkById(100, function ($plans): void {
                foreach ($plans as $plan) {
                    $slug = trim((string) ($plan->slug ?: $plan->key ?: ''));
                    $isEnterprise = $slug === 'enterprise';

                    DB::table('plans')
                        ->where('id', $plan->id)
                        ->update([
                            'is_public' => true,
                            'billing_type' => $isEnterprise ? 'custom' : 'fixed',
                            'is_featured' => (bool) ($plan->is_popular ?? false),
                            'badge' => (bool) ($plan->is_popular ?? false) ? 'Most popular' : null,
                            'updated_at' => now(),
                        ]);
                }
            }, 'id');

        if (! $this->indexExists('plans', 'plans_public_billing_sort_idx')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->index(['is_active', 'is_public', 'billing_type', 'sort_order'], 'plans_public_billing_sort_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if ($this->indexExists('plans', 'plans_public_billing_sort_idx')) {
                $table->dropIndex('plans_public_billing_sort_idx');
            }

            foreach (['price_yearly_cents', 'is_public', 'billing_type', 'is_featured', 'badge'] as $column) {
                if (Schema::hasColumn('plans', $column)) {
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
};
