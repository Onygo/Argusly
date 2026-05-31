<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_assets', function (Blueprint $table): void {
            $table->string('locale', 32)->nullable()->after('language')->index();
        });

        Schema::table('content_audits', function (Blueprint $table): void {
            $table->string('language', 16)->default('en')->after('content_asset_id')->index();
            $table->string('locale', 32)->nullable()->after('language')->index();
        });

        Schema::table('content_lifecycle_scores', function (Blueprint $table): void {
            $table->string('language', 16)->default('en')->after('content_asset_id')->index();
            $table->string('locale', 32)->nullable()->after('language')->index();
        });

        Schema::table('publishing_actions', function (Blueprint $table): void {
            $table->string('language', 16)->default('en')->after('publishing_channel_id')->index();
            $table->string('locale', 32)->nullable()->after('language')->index();
        });

        Schema::create('content_translations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_content_asset_id')->constrained('content_assets')->cascadeOnDelete();
            $table->foreignId('translated_content_asset_id')->nullable()->constrained('content_assets')->nullOnDelete();
            $table->string('source_language', 16)->index();
            $table->string('source_locale', 32)->nullable()->index();
            $table->string('target_language', 16)->index();
            $table->string('target_locale', 32)->nullable()->index();
            $table->string('status')->default('draft')->index();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->json('input_payload')->nullable();
            $table->json('output_payload')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'status']);
            $table->index(['source_content_asset_id', 'target_language', 'status'], 'translations_source_target_status_idx');
            $table->index(['translated_content_asset_id', 'status'], 'translations_asset_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_translations');

        Schema::table('publishing_actions', function (Blueprint $table): void {
            $table->dropColumn(['language', 'locale']);
        });

        Schema::table('content_lifecycle_scores', function (Blueprint $table): void {
            $table->dropColumn(['language', 'locale']);
        });

        Schema::table('content_audits', function (Blueprint $table): void {
            $table->dropColumn(['language', 'locale']);
        });

        Schema::table('generated_assets', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
    }
};
