<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->foreign('current_version_id', 'contents_current_version_fk')
                ->references('id')
                ->on('content_versions')
                ->nullOnDelete();
            $table->foreign('client_site_id', 'contents_client_site_fk')
                ->references('id')
                ->on('client_sites')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropForeign('contents_current_version_fk');
            $table->dropForeign('contents_client_site_fk');
        });
    }
};
