<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_profiles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('official_name');
            $table->string('tagline')->nullable();
            $table->text('short_description')->nullable();
            $table->text('long_description')->nullable();
            $table->text('mission')->nullable();
            $table->text('vision')->nullable();
            $table->text('positioning')->nullable();
            $table->text('value_proposition')->nullable();
            $table->text('tone_of_voice')->nullable();
            $table->text('primary_audience')->nullable();
            $table->text('secondary_audience')->nullable();
            $table->string('website')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'brand_id']);
            $table->index(['account_id', 'brand_id']);
        });

        Schema::create('brand_products', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->string('website')->nullable();
            $table->string('status')->default('draft')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'status']);
        });

        Schema::create('brand_services', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->string('status')->default('draft')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'status']);
        });

        Schema::create('brand_narratives', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description');
            $table->string('importance')->index();
            $table->string('status')->default('draft')->index();
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_narratives');
        Schema::dropIfExists('brand_services');
        Schema::dropIfExists('brand_products');
        Schema::dropIfExists('brand_profiles');
    }
};
