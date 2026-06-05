<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intelligence_signals', function (Blueprint $table): void {
            $table->string('severity')->default('medium')->after('priority')->index();
        });

        Schema::create('signal_alerts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('intelligence_signal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('severity')->index();
            $table->string('status')->default('open')->index();
            $table->string('title');
            $table->text('body')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('triggered_at')->nullable()->index();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'status', 'severity'], 'signal_alerts_scope_status_severity_idx');
            $table->index(['source_type', 'source_id']);
        });

        Schema::table('source_syncs', function (Blueprint $table): void {
            $table->timestamp('next_run_at')->nullable()->after('completed_at')->index();
            $table->json('health')->nullable()->after('error');
        });

        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->timestamp('next_retry_at')->nullable()->after('available_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropColumn('next_retry_at');
        });

        Schema::table('source_syncs', function (Blueprint $table): void {
            $table->dropColumn(['next_run_at', 'health']);
        });

        Schema::dropIfExists('signal_alerts');

        Schema::table('intelligence_signals', function (Blueprint $table): void {
            $table->dropColumn('severity');
        });
    }
};
