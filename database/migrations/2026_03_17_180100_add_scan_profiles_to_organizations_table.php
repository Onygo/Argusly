<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->json('brand_profile')->nullable()->after('tone_defaults');
            $table->json('seo_profile')->nullable()->after('brand_profile');
            $table->json('design_profile')->nullable()->after('seo_profile');
            $table->json('technical_profile')->nullable()->after('design_profile');
            $table->uuid('onboarding_scan_id')->nullable()->after('technical_profile');

            $table->foreign('onboarding_scan_id')->references('id')->on('website_scans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropForeign(['onboarding_scan_id']);
            $table->dropColumn([
                'brand_profile',
                'seo_profile',
                'design_profile',
                'technical_profile',
                'onboarding_scan_id',
            ]);
        });
    }
};
