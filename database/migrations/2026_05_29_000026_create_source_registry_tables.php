<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->index();
            $table->string('provider')->index();
            $table->string('status')->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'status'], 'sources_scope_status_index');
            $table->unique(['account_id', 'brand_id', 'provider', 'name'], 'sources_scope_provider_name_unique');
        });

        Schema::create('source_connections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('configured')->index();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['source_id', 'status']);
            $table->unique(['source_id', 'integration_connection_id'], 'source_connection_unique');
        });

        Schema::create('source_syncs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('planned')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('records_found')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['source_id', 'started_at']);
            $table->index(['source_id', 'status']);
        });

        Schema::table('mentions', function (Blueprint $table): void {
            $table->foreign('source_id')->references('id')->on('sources')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mentions', function (Blueprint $table): void {
            $table->dropForeign(['source_id']);
        });

        Schema::dropIfExists('source_syncs');
        Schema::dropIfExists('source_connections');
        Schema::dropIfExists('sources');
    }
};
