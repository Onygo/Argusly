<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_campaign_metrics', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('email_campaign_export_id');
            $table->unsignedInteger('sent')->default(0);
            $table->unsignedInteger('delivered')->default(0);
            $table->unsignedInteger('opens')->default(0);
            $table->unsignedInteger('unique_opens')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('unique_clicks')->default(0);
            $table->unsignedInteger('bounces')->default(0);
            $table->unsignedInteger('unsubscribes')->default(0);
            $table->unsignedInteger('conversions')->default(0);
            $table->decimal('revenue', 12, 2)->default(0);
            $table->json('raw')->nullable();
            $table->timestamp('measured_at')->nullable();
            $table->timestamps();

            $table->unique('email_campaign_export_id', 'email_metrics_export_unique');

            $table->foreign('email_campaign_export_id', 'email_metrics_export_fk')
                ->references('id')
                ->on('email_campaign_exports')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaign_metrics');
    }
};
