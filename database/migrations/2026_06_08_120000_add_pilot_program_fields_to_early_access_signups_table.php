<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('early_access_signups', function (Blueprint $table): void {
            if (! Schema::hasColumn('early_access_signups', 'phone')) {
                $table->string('phone', 60)->nullable()->after('email');
            }

            if (! Schema::hasColumn('early_access_signups', 'country')) {
                $table->string('country', 120)->nullable()->after('phone');
            }

            if (! Schema::hasColumn('early_access_signups', 'job_title')) {
                $table->string('job_title', 160)->nullable()->after('country');
            }

            if (! Schema::hasColumn('early_access_signups', 'company_size')) {
                $table->string('company_size', 80)->nullable()->after('company_name');
            }

            if (! Schema::hasColumn('early_access_signups', 'industry')) {
                $table->string('industry', 160)->nullable()->after('company_size');
            }

            if (! Schema::hasColumn('early_access_signups', 'priority')) {
                $table->string('priority', 32)->nullable()->after('source')->index();
            }

            if (! Schema::hasColumn('early_access_signups', 'qualification_score')) {
                $table->unsignedTinyInteger('qualification_score')->nullable()->after('priority')->index();
            }

            if (! Schema::hasColumn('early_access_signups', 'assigned_admin_id')) {
                $table->foreignId('assigned_admin_id')->nullable()->after('qualification_score')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('early_access_signups', 'utm_source')) {
                $table->string('utm_source', 160)->nullable()->after('assigned_admin_id');
            }

            if (! Schema::hasColumn('early_access_signups', 'utm_medium')) {
                $table->string('utm_medium', 160)->nullable()->after('utm_source');
            }

            if (! Schema::hasColumn('early_access_signups', 'utm_campaign')) {
                $table->string('utm_campaign', 160)->nullable()->after('utm_medium');
            }

            if (! Schema::hasColumn('early_access_signups', 'marketing_consent')) {
                $table->boolean('marketing_consent')->default(false)->after('utm_campaign');
            }
        });
    }

    public function down(): void
    {
        Schema::table('early_access_signups', function (Blueprint $table): void {
            if (Schema::hasColumn('early_access_signups', 'assigned_admin_id')) {
                $table->dropConstrainedForeignId('assigned_admin_id');
            }

            $columns = [
                'marketing_consent',
                'utm_campaign',
                'utm_medium',
                'utm_source',
                'qualification_score',
                'priority',
                'industry',
                'company_size',
                'job_title',
                'country',
                'phone',
            ];

            $existing = array_values(array_filter(
                $columns,
                fn (string $column): bool => Schema::hasColumn('early_access_signups', $column)
            ));

            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }
};
