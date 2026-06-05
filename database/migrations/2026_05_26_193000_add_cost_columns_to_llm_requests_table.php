<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('llm_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('llm_requests', 'input_cost_eur')) {
                $table->decimal('input_cost_eur', 14, 8)->default(0)->after('credits_consumed');
            }

            if (! Schema::hasColumn('llm_requests', 'output_cost_eur')) {
                $table->decimal('output_cost_eur', 14, 8)->default(0)->after('input_cost_eur');
            }

            if (! Schema::hasColumn('llm_requests', 'total_cost_eur')) {
                $table->decimal('total_cost_eur', 14, 8)->default(0)->after('output_cost_eur');
            }
        });
    }

    public function down(): void
    {
        Schema::table('llm_requests', function (Blueprint $table): void {
            foreach (['total_cost_eur', 'output_cost_eur', 'input_cost_eur'] as $column) {
                if (Schema::hasColumn('llm_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
