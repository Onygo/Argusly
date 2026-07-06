<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_intelligence_report_deliveries', function (Blueprint $table): void {
            $table->unsignedInteger('attempt_count')->default(0)->after('status');
            $table->timestamp('last_attempt_at')->nullable()->after('attempt_count');
            $table->string('provider_message_id')->nullable()->after('failed_at');
            $table->string('provider_status', 80)->nullable()->after('provider_message_id');
            $table->string('failure_category', 120)->nullable()->after('provider_status');
        });
    }

    public function down(): void
    {
        Schema::table('page_intelligence_report_deliveries', function (Blueprint $table): void {
            $table->dropColumn([
                'attempt_count',
                'last_attempt_at',
                'provider_message_id',
                'provider_status',
                'failure_category',
            ]);
        });
    }
};
