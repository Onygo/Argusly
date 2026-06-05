<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('company_name');
            $table->string('industry')->nullable();
            $table->text('value_propositions')->nullable();
            $table->text('proof_points')->nullable();
            $table->text('compliance_rules')->nullable();
            $table->text('banned_claims')->nullable();
            $table->text('target_audience')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unique('workspace_id');
            $table->index('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_profiles');
    }
};
