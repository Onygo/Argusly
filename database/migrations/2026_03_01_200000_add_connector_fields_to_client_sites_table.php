<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_sites', function (Blueprint $table) {
            $table->timestamp('last_heartbeat_at')->nullable()->after('last_healthcheck_at')->index();
            $table->string('connector_platform', 20)->nullable()->after('type')->index();
            $table->string('connector_version', 50)->nullable()->after('plugin_version');
            $table->json('connector_meta')->nullable()->after('capabilities');
        });
    }

    public function down(): void
    {
        Schema::table('client_sites', function (Blueprint $table) {
            $table->dropIndex(['last_heartbeat_at']);
            $table->dropIndex(['connector_platform']);
            $table->dropColumn([
                'last_heartbeat_at',
                'connector_platform',
                'connector_version',
                'connector_meta',
            ]);
        });
    }
};
