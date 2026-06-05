<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            if (! Schema::hasColumn('contents', 'brand_voice_id')) {
                $table->uuid('brand_voice_id')->nullable()->after('generation_mode')->index();
            }

            if (! Schema::hasColumn('contents', 'team_member_id')) {
                $table->unsignedBigInteger('team_member_id')->nullable()->after('brand_voice_id')->index();
            }

            if (! Schema::hasColumn('contents', 'preferred_length')) {
                $table->enum('preferred_length', ['short', 'medium', 'long', 'pillar'])
                    ->nullable()
                    ->after('team_member_id');
            }

            if (! Schema::hasColumn('contents', 'actual_word_count')) {
                $table->unsignedInteger('actual_word_count')->nullable()->after('preferred_length');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            if (Schema::hasColumn('contents', 'actual_word_count')) {
                $table->dropColumn('actual_word_count');
            }

            if (Schema::hasColumn('contents', 'preferred_length')) {
                $table->dropColumn('preferred_length');
            }

            if (Schema::hasColumn('contents', 'team_member_id')) {
                $table->dropColumn('team_member_id');
            }

            if (Schema::hasColumn('contents', 'brand_voice_id')) {
                $table->dropColumn('brand_voice_id');
            }
        });
    }
};
