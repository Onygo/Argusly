<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('connector_sync_runs')) {
            return;
        }

        $this->addJsonColumnIfMissing('cursor_before_json');
        $this->addJsonColumnIfMissing('cursor_after_json');
        $this->addUnsignedIntegerColumnIfMissing('duration_ms', nullable: true);
        $this->addUnsignedIntegerColumnIfMissing('records_processed', nullable: false, default: 0);
        $this->addJsonColumnIfMissing('metrics_json');
        $this->addJsonColumnIfMissing('rate_limit_json');
        $this->addJsonColumnIfMissing('retry_json');
        $this->addTimestampColumnIfMissing('next_retry_at');
        $this->addTimestampColumnIfMissing('cancelled_at');
        $this->addStringColumnIfMissing('idempotency_key', 191);
        $this->addSoftDeletesIfMissing();
    }

    public function down(): void
    {
        // Intentionally no-op: this repairs columns owned by the core connector migration.
    }

    private function addJsonColumnIfMissing(string $column): void
    {
        if (Schema::hasColumn('connector_sync_runs', $column)) {
            return;
        }

        Schema::table('connector_sync_runs', function (Blueprint $table) use ($column): void {
            $table->json($column)->nullable();
        });
    }

    private function addUnsignedIntegerColumnIfMissing(string $column, bool $nullable, ?int $default = null): void
    {
        if (Schema::hasColumn('connector_sync_runs', $column)) {
            return;
        }

        Schema::table('connector_sync_runs', function (Blueprint $table) use ($column, $nullable, $default): void {
            $definition = $table->unsignedInteger($column);

            if ($nullable) {
                $definition->nullable();
            }

            if ($default !== null) {
                $definition->default($default);
            }
        });
    }

    private function addTimestampColumnIfMissing(string $column): void
    {
        if (Schema::hasColumn('connector_sync_runs', $column)) {
            return;
        }

        Schema::table('connector_sync_runs', function (Blueprint $table) use ($column): void {
            $table->timestamp($column)->nullable();
        });
    }

    private function addStringColumnIfMissing(string $column, int $length): void
    {
        if (Schema::hasColumn('connector_sync_runs', $column)) {
            return;
        }

        Schema::table('connector_sync_runs', function (Blueprint $table) use ($column, $length): void {
            $table->string($column, $length)->nullable();
        });
    }

    private function addSoftDeletesIfMissing(): void
    {
        if (Schema::hasColumn('connector_sync_runs', 'deleted_at')) {
            return;
        }

        Schema::table('connector_sync_runs', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }
};
