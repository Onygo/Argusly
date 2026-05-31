<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publishing_channels', function (Blueprint $table): void {
            if (! Schema::hasColumn('publishing_channels', 'connector_installation_id')) {
                $table->foreignId('connector_installation_id')
                    ->nullable()
                    ->after('property_id')
                    ->constrained('connector_installations')
                    ->nullOnDelete();

                $table->index(['account_id', 'brand_id', 'connector_installation_id'], 'pub_channels_connector_scope_idx');
            }
        });

        DB::table('connector_installations')
            ->whereNotNull('channel_id')
            ->orderBy('id')
            ->each(function ($installation): void {
                DB::table('publishing_channels')
                    ->where('id', $installation->channel_id)
                    ->whereNull('connector_installation_id')
                    ->update(['connector_installation_id' => $installation->id]);
            });
    }

    public function down(): void
    {
        Schema::table('publishing_channels', function (Blueprint $table): void {
            if (Schema::hasColumn('publishing_channels', 'connector_installation_id')) {
                $table->dropIndex('pub_channels_connector_scope_idx');
                $table->dropConstrainedForeignId('connector_installation_id');
            }
        });
    }
};
