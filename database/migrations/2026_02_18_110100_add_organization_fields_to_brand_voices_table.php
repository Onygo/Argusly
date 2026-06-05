<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_voices', function (Blueprint $table) {
            if (! Schema::hasColumn('brand_voices', 'organization_id')) {
                $table->unsignedBigInteger('organization_id')->nullable()->after('workspace_id')->index();
            }

            if (! Schema::hasColumn('brand_voices', 'tone_of_voice')) {
                $table->text('tone_of_voice')->nullable()->after('name');
            }

            if (! Schema::hasColumn('brand_voices', 'writing_style')) {
                $table->text('writing_style')->nullable()->after('tone_of_voice');
            }

            if (! Schema::hasColumn('brand_voices', 'do_rules')) {
                $table->text('do_rules')->nullable()->after('writing_style');
            }

            if (! Schema::hasColumn('brand_voices', 'dont_rules')) {
                $table->text('dont_rules')->nullable()->after('do_rules');
            }

            if (! Schema::hasColumn('brand_voices', 'vocabulary_guidelines')) {
                $table->text('vocabulary_guidelines')->nullable()->after('dont_rules');
            }
        });
    }

    public function down(): void
    {
        Schema::table('brand_voices', function (Blueprint $table) {
            if (Schema::hasColumn('brand_voices', 'vocabulary_guidelines')) {
                $table->dropColumn('vocabulary_guidelines');
            }

            if (Schema::hasColumn('brand_voices', 'dont_rules')) {
                $table->dropColumn('dont_rules');
            }

            if (Schema::hasColumn('brand_voices', 'do_rules')) {
                $table->dropColumn('do_rules');
            }

            if (Schema::hasColumn('brand_voices', 'writing_style')) {
                $table->dropColumn('writing_style');
            }

            if (Schema::hasColumn('brand_voices', 'tone_of_voice')) {
                $table->dropColumn('tone_of_voice');
            }

            if (Schema::hasColumn('brand_voices', 'organization_id')) {
                $table->dropColumn('organization_id');
            }
        });
    }
};
