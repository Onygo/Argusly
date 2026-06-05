<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            if (! Schema::hasColumn('organizations', 'access_tier')) {
                $table->string('access_tier', 32)->nullable()->after('billing_address');
            }

            if (! Schema::hasColumn('organizations', 'early_bird_started_at')) {
                $table->timestamp('early_bird_started_at')->nullable()->after('access_tier');
            }

            if (! Schema::hasColumn('organizations', 'early_bird_ends_at')) {
                $table->timestamp('early_bird_ends_at')->nullable()->after('early_bird_started_at');
            }

            if (! Schema::hasColumn('organizations', 'early_bird_note')) {
                $table->text('early_bird_note')->nullable()->after('early_bird_ends_at');
            }

            if (! Schema::hasColumn('organizations', 'converted_to_paid_at')) {
                $table->timestamp('converted_to_paid_at')->nullable()->after('early_bird_note');
            }

            if (! Schema::hasColumn('organizations', 'access_updated_by')) {
                $table->foreignId('access_updated_by')->nullable()->after('converted_to_paid_at')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            if (Schema::hasColumn('organizations', 'access_updated_by')) {
                $table->dropConstrainedForeignId('access_updated_by');
            }

            $columns = [
                'converted_to_paid_at',
                'early_bird_note',
                'early_bird_ends_at',
                'early_bird_started_at',
                'access_tier',
            ];

            $existing = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn('organizations', $column)));

            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }
};
