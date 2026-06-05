<?php

use App\Models\FeatureFlag;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('visibility_scores')) {
            Schema::create('visibility_scores', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
                $table->foreignId('visibility_check_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('visibility_result_id')->nullable()->constrained()->nullOnDelete();
                $table->string('provider')->index();
                $table->string('model')->nullable()->index();
                $table->string('prompt_hash', 64)->index();
                $table->unsignedTinyInteger('answer_presence_score')->default(0);
                $table->unsignedTinyInteger('citation_score')->default(0);
                $table->unsignedTinyInteger('source_presence_score')->default(0);
                $table->unsignedTinyInteger('authority_score')->default(0);
                $table->unsignedTinyInteger('competitor_presence_score')->default(0);
                $table->unsignedTinyInteger('ai_attention_score')->default(0);
                $table->text('summary')->nullable();
                $table->json('raw_metrics_json')->nullable();
                $table->timestamps();

                $table->index(['account_id', 'brand_id', 'created_at']);
                $table->index(['brand_id', 'provider', 'model']);
                $table->unique(['visibility_result_id', 'provider', 'model', 'prompt_hash'], 'visibility_scores_result_provider_unique');
            });
        }

        if (! Schema::hasColumn('visibility_citations', 'source_domain')) {
            Schema::table('visibility_citations', function (Blueprint $table): void {
                $table->foreignId('visibility_check_id')->nullable()->after('brand_id')->constrained()->nullOnDelete();
                $table->text('source_url')->nullable()->after('provider_run_id');
                $table->string('source_domain')->nullable()->after('source_url')->index();
                $table->string('source_title')->nullable()->after('source_domain');
                $table->string('citation_type')->default('external')->after('source_title')->index();
                $table->boolean('is_owned_source')->default(false)->after('citation_type')->index();
                $table->boolean('is_competitor_source')->default(false)->after('is_owned_source')->index();
                $table->unsignedTinyInteger('confidence_score')->nullable()->after('is_competitor_source');
                $table->json('metadata_json')->nullable()->after('confidence_score');

                $table->index(['brand_id', 'visibility_check_id'], 'vis_citations_brand_check_idx');
            });
        }

        if (! Schema::hasTable('visibility_sources')) {
            Schema::create('visibility_sources', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
                $table->string('domain')->index();
                $table->string('source_type')->default('external')->index();
                $table->boolean('is_owned')->default(false)->index();
                $table->boolean('is_competitor')->default(false)->index();
                $table->unsignedTinyInteger('authority_score')->default(0);
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->json('metadata_json')->nullable();
                $table->timestamps();

                $table->unique(['brand_id', 'domain']);
                $table->index(['account_id', 'brand_id', 'source_type']);
            });
        }

        if (! Schema::hasTable('visibility_trends')) {
            Schema::create('visibility_trends', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
                $table->date('period_date')->index();
                $table->string('period')->default('day')->index();
                $table->string('provider')->nullable()->index();
                $table->unsignedTinyInteger('answer_presence_score')->nullable();
                $table->unsignedTinyInteger('citation_score')->nullable();
                $table->unsignedTinyInteger('source_presence_score')->nullable();
                $table->unsignedTinyInteger('authority_score')->nullable();
                $table->unsignedTinyInteger('competitor_presence_score')->nullable();
                $table->unsignedTinyInteger('ai_attention_score')->nullable();
                $table->unsignedInteger('scores_count')->default(0);
                $table->json('metadata_json')->nullable();
                $table->timestamps();

                $table->unique(['brand_id', 'period', 'period_date', 'provider'], 'visibility_trends_scope_unique');
                $table->index(['account_id', 'brand_id', 'period_date']);
            });
        }

        if (! Schema::hasTable('visibility_competitor_snapshots')) {
            Schema::create('visibility_competitor_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
                $table->foreignId('competitor_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('visibility_check_id')->nullable()->constrained()->nullOnDelete();
                $table->string('provider')->nullable()->index();
                $table->string('competitor_name');
                $table->unsignedSmallInteger('mentions_count')->default(0);
                $table->unsignedTinyInteger('presence_score')->default(0);
                $table->timestamp('captured_at')->index();
                $table->json('metadata_json')->nullable();
                $table->timestamps();

                $table->index(['account_id', 'brand_id', 'captured_at'], 'vis_comp_snap_scope_captured_idx');
                $table->index(['brand_id', 'competitor_name'], 'vis_comp_snap_brand_name_idx');
            });
        }

        FeatureFlag::query()->updateOrCreate(
            ['key' => 'ai_visibility_deep_scoring'],
            [
                'name' => 'AI visibility deep scoring',
                'description' => 'Enables AI attention, citation intelligence, source presence and competitor scoring on brand visibility pages.',
                'scope' => 'platform',
                'enabled' => true,
                'rules' => ['module' => 'visibility'],
            ],
        );
    }

    public function down(): void
    {
        FeatureFlag::query()->where('key', 'ai_visibility_deep_scoring')->delete();

        Schema::dropIfExists('visibility_competitor_snapshots');
        Schema::dropIfExists('visibility_trends');
        Schema::dropIfExists('visibility_sources');

        Schema::table('visibility_citations', function (Blueprint $table): void {
            $table->dropIndex(['brand_id', 'visibility_check_id']);
            $table->dropForeign(['visibility_check_id']);
            $table->dropColumn([
                'visibility_check_id',
                'source_url',
                'source_domain',
                'source_title',
                'citation_type',
                'is_owned_source',
                'is_competitor_source',
                'confidence_score',
                'metadata_json',
            ]);
        });

        Schema::dropIfExists('visibility_scores');
    }
};
