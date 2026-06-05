<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_contexts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->longText('raw_input')->nullable();
            $table->json('structured_json')->nullable();
            $table->string('source_type', 64);
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->index(['workspace_id', 'created_at'], 'brand_contexts_workspace_created_idx');
        });

        Schema::table('company_profiles', function (Blueprint $table): void {
            $table->text('short_description')->nullable()->after('industry');
            $table->text('long_description')->nullable()->after('short_description');
            $table->text('mission')->nullable()->after('long_description');
            $table->text('vision')->nullable()->after('mission');
            $table->text('value_proposition')->nullable()->after('vision');
            $table->text('key_services')->nullable()->after('value_proposition');
            $table->uuid('generated_from_context_id')->nullable()->after('target_audience')->index();

            $table->foreign('generated_from_context_id')
                ->references('id')
                ->on('brand_contexts')
                ->nullOnDelete();
        });

        Schema::table('brand_voices', function (Blueprint $table): void {
            $table->text('example_paragraph')->nullable()->after('writing_style');
            $table->uuid('generated_from_context_id')->nullable()->after('organization_id')->index();

            $table->foreign('generated_from_context_id')
                ->references('id')
                ->on('brand_contexts')
                ->nullOnDelete();
        });

        Schema::table('personas', function (Blueprint $table): void {
            $table->uuid('generated_from_context_id')->nullable()->after('organization_id')->index();

            $table->foreign('generated_from_context_id')
                ->references('id')
                ->on('brand_contexts')
                ->nullOnDelete();
        });

        Schema::table('team_members', function (Blueprint $table): void {
            $table->uuid('generated_from_context_id')->nullable()->after('organization_id')->index();

            $table->foreign('generated_from_context_id')
                ->references('id')
                ->on('brand_contexts')
                ->nullOnDelete();
        });

        Schema::table('contents', function (Blueprint $table): void {
            $table->unsignedBigInteger('buyer_persona_id')->nullable()->after('brand_voice_id')->index();

            $table->foreign('buyer_persona_id')
                ->references('id')
                ->on('personas')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->dropForeign(['buyer_persona_id']);
            $table->dropColumn('buyer_persona_id');
        });

        Schema::table('team_members', function (Blueprint $table): void {
            $table->dropForeign(['generated_from_context_id']);
            $table->dropColumn('generated_from_context_id');
        });

        Schema::table('personas', function (Blueprint $table): void {
            $table->dropForeign(['generated_from_context_id']);
            $table->dropColumn('generated_from_context_id');
        });

        Schema::table('brand_voices', function (Blueprint $table): void {
            $table->dropForeign(['generated_from_context_id']);
            $table->dropColumn(['example_paragraph', 'generated_from_context_id']);
        });

        Schema::table('company_profiles', function (Blueprint $table): void {
            $table->dropForeign(['generated_from_context_id']);
            $table->dropColumn([
                'short_description',
                'long_description',
                'mission',
                'vision',
                'value_proposition',
                'key_services',
                'generated_from_context_id',
            ]);
        });

        Schema::dropIfExists('brand_contexts');
    }
};
