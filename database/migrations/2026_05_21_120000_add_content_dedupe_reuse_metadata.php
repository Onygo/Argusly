<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $after = Schema::hasColumn('contents', 'duplicate_checked_at')
            ? 'duplicate_checked_at'
            : (Schema::hasColumn('contents', 'dedupe_fingerprint')
                ? 'dedupe_fingerprint'
                : (Schema::hasColumn('contents', 'external_key') ? 'external_key' : null));

        Schema::table('contents', function (Blueprint $table) use ($after): void {
            if (! Schema::hasColumn('contents', 'dedupe_was_reused')) {
                $column = $table->boolean('dedupe_was_reused')->default(false);
                if ($after !== null) {
                    $column->after($after);
                }
            }

            if (! Schema::hasColumn('contents', 'dedupe_reused_at')) {
                $table->timestamp('dedupe_reused_at')->nullable()->after('dedupe_was_reused');
            }

            if (! Schema::hasColumn('contents', 'dedupe_reuse_reason')) {
                $table->string('dedupe_reuse_reason', 80)->nullable()->after('dedupe_reused_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            if (Schema::hasColumn('contents', 'dedupe_reuse_reason')) {
                $table->dropColumn('dedupe_reuse_reason');
            }

            if (Schema::hasColumn('contents', 'dedupe_reused_at')) {
                $table->dropColumn('dedupe_reused_at');
            }

            if (Schema::hasColumn('contents', 'dedupe_was_reused')) {
                $table->dropColumn('dedupe_was_reused');
            }
        });
    }
};
