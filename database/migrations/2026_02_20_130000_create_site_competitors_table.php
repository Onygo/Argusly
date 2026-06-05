<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_competitors', function (Blueprint $table) {
            $table->id();
            $table->uuid('workspace_id');
            $table->uuid('client_site_id');
            $table->string('name', 120);
            $table->string('domain', 190);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['client_site_id', 'domain'], 'site_competitors_site_domain_unique');
            $table->index(['workspace_id', 'client_site_id', 'is_active'], 'site_competitors_scope_active_idx');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_competitors');
    }
};
