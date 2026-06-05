<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            if (! Schema::hasColumn('workspaces', 'low_credit_warning_state')) {
                $table->string('low_credit_warning_state')->nullable()->after('enabled_content_languages');
            }

            if (! Schema::hasColumn('workspaces', 'low_credit_warning_sent_at')) {
                $table->timestamp('low_credit_warning_sent_at')->nullable()->after('low_credit_warning_state');
            }

            if (! Schema::hasColumn('workspaces', 'low_credit_warning_last_available')) {
                $table->integer('low_credit_warning_last_available')->nullable()->after('low_credit_warning_sent_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            foreach ([
                'low_credit_warning_last_available',
                'low_credit_warning_sent_at',
                'low_credit_warning_state',
            ] as $column) {
                if (Schema::hasColumn('workspaces', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
