<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intelligence_signals', function (Blueprint $table): void {
            $table->string('category')->default('system')->after('type')->index();
            $table->string('priority')->default('medium')->after('category')->index();
            $table->string('dedupe_key')->nullable()->after('priority');
            $table->timestamp('reviewed_at')->nullable()->after('detected_at');
            $table->timestamp('dismissed_at')->nullable()->after('reviewed_at');

            $table->unique(['account_id', 'dedupe_key'], 'intelligence_signals_account_dedupe_unique');
            $table->index(['account_id', 'category', 'priority', 'detected_at'], 'intelligence_signals_feed_filter_index');
        });
    }

    public function down(): void
    {
        Schema::table('intelligence_signals', function (Blueprint $table): void {
            $table->dropUnique('intelligence_signals_account_dedupe_unique');
            $table->dropIndex('intelligence_signals_feed_filter_index');
            $table->dropColumn(['category', 'priority', 'dedupe_key', 'reviewed_at', 'dismissed_at']);
        });
    }
};
