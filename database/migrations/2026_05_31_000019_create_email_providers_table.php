<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_providers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->index();
            $table->string('name');
            $table->string('status')->default('active')->index();
            $table->json('settings')->nullable();
            $table->text('credentials')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'status']);
        });

        Schema::table('newsletters', function (Blueprint $table): void {
            $table->foreignId('email_provider_id')
                ->nullable()
                ->after('campaign_id')
                ->constrained('email_providers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('newsletters', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('email_provider_id');
        });

        Schema::dropIfExists('email_providers');
    }
};
