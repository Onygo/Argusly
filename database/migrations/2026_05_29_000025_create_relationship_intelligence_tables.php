<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('display_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('website', 2048)->nullable();
            $table->string('linkedin_url', 2048)->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'last_name', 'first_name']);
            $table->index(['account_id', 'email']);
        });

        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('website', 2048)->nullable();
            $table->string('industry')->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'name']);
            $table->index(['account_id', 'industry']);
        });

        Schema::create('relationships', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('from_type', 120);
            $table->unsignedBigInteger('from_id');
            $table->string('to_type', 120);
            $table->unsignedBigInteger('to_id');
            $table->string('relationship_type', 64)->index();
            $table->unsignedTinyInteger('strength')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'relationship_type']);
            $table->index(['from_type', 'from_id']);
            $table->index(['to_type', 'to_id']);
            $table->unique(['account_id', 'from_type', 'from_id', 'to_type', 'to_id', 'relationship_type'], 'relationships_unique_edge');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relationships');
        Schema::dropIfExists('organizations');
        Schema::dropIfExists('contacts');
    }
};
