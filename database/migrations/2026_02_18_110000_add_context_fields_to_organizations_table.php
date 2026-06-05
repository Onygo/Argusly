<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (! Schema::hasColumn('organizations', 'company_description')) {
                $table->text('company_description')->nullable()->after('webhook_url');
            }

            if (! Schema::hasColumn('organizations', 'positioning_statement')) {
                $table->text('positioning_statement')->nullable()->after('company_description');
            }

            if (! Schema::hasColumn('organizations', 'target_audience')) {
                $table->text('target_audience')->nullable()->after('positioning_statement');
            }

            if (! Schema::hasColumn('organizations', 'industry')) {
                $table->string('industry')->nullable()->after('target_audience');
            }

            if (! Schema::hasColumn('organizations', 'tone_defaults')) {
                $table->json('tone_defaults')->nullable()->after('industry');
            }
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (Schema::hasColumn('organizations', 'tone_defaults')) {
                $table->dropColumn('tone_defaults');
            }

            if (Schema::hasColumn('organizations', 'industry')) {
                $table->dropColumn('industry');
            }

            if (Schema::hasColumn('organizations', 'target_audience')) {
                $table->dropColumn('target_audience');
            }

            if (Schema::hasColumn('organizations', 'positioning_statement')) {
                $table->dropColumn('positioning_statement');
            }

            if (Schema::hasColumn('organizations', 'company_description')) {
                $table->dropColumn('company_description');
            }
        });
    }
};
