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
            if (! Schema::hasColumn('plans', 'slug')) {
                $table->string('slug', 120)->nullable()->after('key');
            }

            if (! Schema::hasColumn('plans', 'description_short')) {
                $table->string('description_short', 255)->nullable()->after('name');
            }

            if (! Schema::hasColumn('plans', 'price_monthly_cents')) {
                $table->unsignedInteger('price_monthly_cents')->nullable()->after('monthly_price_cents');
            }

            if (! Schema::hasColumn('plans', 'is_popular')) {
                $table->boolean('is_popular')->default(false)->after('is_active');
            }

            if (! Schema::hasColumn('plans', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(999999)->after('is_popular');
            }

            if (! Schema::hasColumn('plans', 'cta_label')) {
                $table->string('cta_label', 120)->nullable()->after('sort_order');
            }

            if (! Schema::hasColumn('plans', 'cta_href')) {
                $table->string('cta_href', 255)->nullable()->after('cta_label');
            }
        });

        DB::table('plans')->orderBy('created_at')->chunkById(100, function ($plans): void {
            foreach ($plans as $plan) {
                $limits = is_array($plan->limits) ? $plan->limits : json_decode((string) $plan->limits, true);
                $sortOrder = is_array($limits) ? (int) ($limits['sort_order'] ?? 999999) : 999999;

                DB::table('plans')
                    ->where('id', $plan->id)
                    ->update([
                        'slug' => $plan->slug ?: $plan->key,
                        'description_short' => $plan->description_short ?: (is_array($limits) ? ($limits['description'] ?? null) : null),
                        'price_monthly_cents' => $plan->price_monthly_cents ?? $plan->monthly_price_cents ?? $plan->price_cents,
                        'sort_order' => $plan->sort_order ?? $sortOrder,
                    ]);
            }
        }, 'id');

        if (! $this->indexExists('plans', 'plans_slug_unique')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->unique('slug', 'plans_slug_unique');
            });
        }

        if (! $this->indexExists('plans', 'plans_active_sort_idx')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->index(['is_active', 'sort_order'], 'plans_active_sort_idx');
            });
        }

        Schema::table('plan_features', function (Blueprint $table) {
            if (! Schema::hasColumn('plan_features', 'label')) {
                $table->string('label', 255)->nullable()->after('feature_key');
            }

            if (! Schema::hasColumn('plan_features', 'feature_group')) {
                $table->string('feature_group', 120)->nullable()->after('label');
            }

            if (! Schema::hasColumn('plan_features', 'is_highlight')) {
                $table->boolean('is_highlight')->default(false)->after('feature_group');
            }

            if (! Schema::hasColumn('plan_features', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(999999)->after('is_highlight');
            }

            if (! Schema::hasColumn('plan_features', 'locale')) {
                $table->string('locale', 12)->nullable()->after('sort_order');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plan_features', function (Blueprint $table) {
            foreach (['label', 'feature_group', 'is_highlight', 'sort_order', 'locale'] as $column) {
                if (Schema::hasColumn('plan_features', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('plans', function (Blueprint $table) {
            if ($this->indexExists('plans', 'plans_slug_unique')) {
                $table->dropUnique('plans_slug_unique');
            }

            if ($this->indexExists('plans', 'plans_active_sort_idx')) {
                $table->dropIndex('plans_active_sort_idx');
            }

            foreach (['slug', 'description_short', 'price_monthly_cents', 'is_popular', 'sort_order', 'cta_label', 'cta_href'] as $column) {
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

