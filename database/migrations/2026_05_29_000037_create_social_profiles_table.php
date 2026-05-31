<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_profiles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('integration_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider')->index();
            $table->string('provider_profile_id')->nullable()->index();
            $table->string('display_name');
            $table->string('profile_url')->nullable();
            $table->string('avatar_url')->nullable();
            $table->string('type')->default('person')->index();
            $table->string('status')->default('connected')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['owner_user_id', 'provider']);
            $table->index(['account_id', 'brand_id']);
            $table->index(['provider', 'provider_profile_id']);
        });

        Schema::create('social_profile_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('social_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('can_view')->default(false);
            $table->boolean('can_prepare')->default(false);
            $table->boolean('can_schedule')->default(false);
            $table->boolean('can_publish')->default(false);
            $table->boolean('can_manage')->default(false);
            $table->timestamps();

            $table->index(['account_id', 'brand_id']);
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_profile_permissions');
        Schema::dropIfExists('social_profiles');
    }
};
