<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('connector_datasets')) {
            return;
        }

        $this->addUuidColumnIfMissing('connector_account_id');
        $this->addUuidColumnIfMissing('workspace_id');
        $this->addUuidColumnIfMissing('client_site_id');
        $this->addStringColumnIfMissing('provider_key', 120);
        $this->addStringColumnIfMissing('dataset_key', 160);
        $this->addStringColumnIfMissing('dataset_type', 80);
        $this->addStringColumnIfMissing('external_dataset_id', 191);
        $this->addStringColumnIfMissing('display_name', 220);
        $this->addStringColumnIfMissing('status', 40, default: 'active');
        $this->addStringColumnIfMissing('sync_frequency', 80);
        $this->addTimestampColumnIfMissing('next_sync_at');
        $this->addTimestampColumnIfMissing('last_sync_at');
        $this->addTimestampColumnIfMissing('discovered_at');
        $this->addTimestampColumnIfMissing('last_seen_at');
        $this->addTimestampColumnIfMissing('deactivated_at');
        $this->addStringColumnIfMissing('health_status', 40);
        $this->addStringColumnIfMissing('health_severity', 40);
        $this->addUuidColumnIfMissing('latest_health_event_id');
        $this->addTimestampColumnIfMissing('health_checked_at');
        $this->addJsonColumnIfMissing('cursor_json');
        $this->addJsonColumnIfMissing('capabilities_json');
        $this->addJsonColumnIfMissing('sync_config_json');
        $this->addJsonColumnIfMissing('config_json');
        $this->addJsonColumnIfMissing('metadata_json');
        $this->addSoftDeletesIfMissing();
    }

    public function down(): void
    {
        // Intentionally no-op: this repairs columns owned by the core connector migration.
    }

    private function addUuidColumnIfMissing(string $column): void
    {
        if (Schema::hasColumn('connector_datasets', $column)) {
            return;
        }

        Schema::table('connector_datasets', function (Blueprint $table) use ($column): void {
            $table->uuid($column)->nullable();
        });
    }

    private function addStringColumnIfMissing(string $column, int $length, ?string $default = null): void
    {
        if (Schema::hasColumn('connector_datasets', $column)) {
            return;
        }

        Schema::table('connector_datasets', function (Blueprint $table) use ($column, $length, $default): void {
            $definition = $table->string($column, $length)->nullable();

            if ($default !== null) {
                $definition->default($default);
            }
        });
    }

    private function addTimestampColumnIfMissing(string $column): void
    {
        if (Schema::hasColumn('connector_datasets', $column)) {
            return;
        }

        Schema::table('connector_datasets', function (Blueprint $table) use ($column): void {
            $table->timestamp($column)->nullable();
        });
    }

    private function addJsonColumnIfMissing(string $column): void
    {
        if (Schema::hasColumn('connector_datasets', $column)) {
            return;
        }

        Schema::table('connector_datasets', function (Blueprint $table) use ($column): void {
            $table->json($column)->nullable();
        });
    }

    private function addSoftDeletesIfMissing(): void
    {
        if (Schema::hasColumn('connector_datasets', 'deleted_at')) {
            return;
        }

        Schema::table('connector_datasets', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }
};
