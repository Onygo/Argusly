<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faq_opportunity_audits', function (Blueprint $table): void {
            $table->string('status', 40)->default('pending')->index()->after('solution_type');
            $table->text('error_message')->nullable()->after('suggested_ctas');
            $table->timestamp('completed_at')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('faq_opportunity_audits', function (Blueprint $table): void {
            $table->dropColumn(['status', 'error_message', 'completed_at']);
        });
    }
};
