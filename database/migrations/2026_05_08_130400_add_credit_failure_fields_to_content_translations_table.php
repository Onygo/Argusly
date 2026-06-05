<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_translations', function (Blueprint $table): void {
            if (! Schema::hasColumn('content_translations', 'failure_reason')) {
                $table->string('failure_reason')->nullable()->after('status');
            }

            if (! Schema::hasColumn('content_translations', 'required_credits')) {
                $table->unsignedInteger('required_credits')->nullable()->after('failure_reason');
            }

            if (! Schema::hasColumn('content_translations', 'available_credits')) {
                $table->unsignedInteger('available_credits')->nullable()->after('required_credits');
            }

            if (! Schema::hasColumn('content_translations', 'entitlement_source')) {
                $table->string('entitlement_source')->nullable()->after('available_credits');
            }
        });
    }

    public function down(): void
    {
        Schema::table('content_translations', function (Blueprint $table): void {
            foreach (['entitlement_source', 'available_credits', 'required_credits', 'failure_reason'] as $column) {
                if (Schema::hasColumn('content_translations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
