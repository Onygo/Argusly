<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visibility_prompt_templates', function (Blueprint $table): void {
            $table->string('language', 16)->default('en')->after('prompt')->index();
        });

        Schema::table('visibility_provider_runs', function (Blueprint $table): void {
            $table->string('language', 16)->default('en')->after('query')->index();
            $table->string('locale', 32)->nullable()->after('language')->index();
            $table->string('market', 64)->nullable()->after('locale')->index();
            $table->string('persona')->nullable()->after('market');
            $table->string('intent')->nullable()->after('persona')->index();
            $table->string('input_language', 16)->default('en')->after('intent')->index();
            $table->string('target_market', 64)->nullable()->after('input_language')->index();
            $table->string('normalized_answer_language', 16)->default('en')->after('normalized_answer')->index();
            $table->string('detected_language', 16)->nullable()->after('normalized_answer_language')->index();
        });

        Schema::table('visibility_run_schedules', function (Blueprint $table): void {
            $table->string('language', 16)->nullable()->after('provider')->index();
            $table->string('locale', 32)->nullable()->after('language')->index();
            $table->string('market', 64)->nullable()->after('locale')->index();
            $table->string('persona')->nullable()->after('market');
            $table->string('intent')->nullable()->after('persona')->index();
        });

        Schema::table('visibility_results', function (Blueprint $table): void {
            $table->string('language', 16)->default('en')->after('query')->index();
            $table->string('locale', 32)->nullable()->after('language')->index();
            $table->string('market', 64)->nullable()->after('locale')->index();
            $table->string('persona')->nullable()->after('market');
            $table->string('intent')->nullable()->after('persona')->index();
        });

        Schema::table('visibility_snapshots', function (Blueprint $table): void {
            $table->string('language', 16)->nullable()->after('provider')->index();
            $table->string('locale', 32)->nullable()->after('language')->index();
            $table->string('market', 64)->nullable()->after('locale')->index();
            $table->string('persona')->nullable()->after('market');
            $table->string('intent')->nullable()->after('persona')->index();
        });
    }

    public function down(): void
    {
        Schema::table('visibility_snapshots', function (Blueprint $table): void {
            $table->dropColumn(['language', 'locale', 'market', 'persona', 'intent']);
        });

        Schema::table('visibility_results', function (Blueprint $table): void {
            $table->dropColumn(['language', 'locale', 'market', 'persona', 'intent']);
        });

        Schema::table('visibility_run_schedules', function (Blueprint $table): void {
            $table->dropColumn(['language', 'locale', 'market', 'persona', 'intent']);
        });

        Schema::table('visibility_provider_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'language',
                'locale',
                'market',
                'persona',
                'intent',
                'input_language',
                'target_market',
                'normalized_answer_language',
                'detected_language',
            ]);
        });

        Schema::table('visibility_prompt_templates', function (Blueprint $table): void {
            $table->dropColumn('language');
        });
    }
};
