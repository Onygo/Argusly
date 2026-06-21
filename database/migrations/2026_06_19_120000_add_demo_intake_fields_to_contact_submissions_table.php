<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table): void {
            $table->string('website', 500)->nullable()->after('company');
            $table->string('market', 190)->nullable()->after('website');
            $table->text('competitors')->nullable()->after('market');
            $table->string('growth_goal', 190)->nullable()->after('competitors');
            $table->string('interest_area', 120)->nullable()->after('growth_goal');

            $table->index(['interest_area']);
        });
    }

    public function down(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table): void {
            $table->dropIndex(['interest_area']);
            $table->dropColumn([
                'website',
                'market',
                'competitors',
                'growth_goal',
                'interest_area',
            ]);
        });
    }
};
