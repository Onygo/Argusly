<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('briefs', function (Blueprint $table) {
            $table->uuid('content_id')->nullable()->after('client_site_id')->index();
            $table->foreign('content_id')->references('id')->on('contents')->nullOnDelete();
        });

        Schema::table('drafts', function (Blueprint $table) {
            $table->uuid('content_id')->nullable()->after('brief_id')->index();
            $table->foreign('content_id')->references('id')->on('contents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->dropForeign(['content_id']);
            $table->dropIndex(['content_id']);
            $table->dropColumn('content_id');
        });

        Schema::table('briefs', function (Blueprint $table) {
            $table->dropForeign(['content_id']);
            $table->dropIndex(['content_id']);
            $table->dropColumn('content_id');
        });
    }
};
