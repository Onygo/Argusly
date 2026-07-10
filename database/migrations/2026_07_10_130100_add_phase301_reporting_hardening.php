<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->string('reporting_timezone', 80)->nullable()->after('visual_settings');
        });

        Schema::table('connector_normalized_daily_performances', function (Blueprint $table): void {
            $table->string('original_currency', 16)->nullable()->after('date')->index();
            $table->string('reporting_currency', 16)->nullable()->after('original_currency')->index();
            $table->decimal('original_cost', 20, 6)->nullable()->after('cost');
            $table->decimal('reporting_cost', 20, 6)->nullable()->after('original_cost');
            $table->decimal('original_revenue', 20, 6)->nullable()->after('revenue');
            $table->decimal('reporting_revenue', 20, 6)->nullable()->after('original_revenue');
            $table->decimal('conversion_rate', 20, 10)->nullable()->after('reporting_revenue');
            $table->date('conversion_rate_date')->nullable()->after('conversion_rate');
            $table->string('conversion_rate_source', 120)->nullable()->after('conversion_rate_date');
        });

        DB::table('connector_normalized_daily_performances')
            ->whereNull('original_cost')
            ->update(['original_cost' => DB::raw('cost')]);

        DB::table('connector_normalized_daily_performances')
            ->whereNull('original_revenue')
            ->whereNotNull('revenue')
            ->update(['original_revenue' => DB::raw('revenue')]);

        $this->backfillReliableOriginalCurrencies();
    }

    public function down(): void
    {
        Schema::table('connector_normalized_daily_performances', function (Blueprint $table): void {
            $table->dropIndex(['original_currency']);
            $table->dropIndex(['reporting_currency']);
            $table->dropColumn([
                'original_currency',
                'reporting_currency',
                'original_cost',
                'reporting_cost',
                'original_revenue',
                'reporting_revenue',
                'conversion_rate',
                'conversion_rate_date',
                'conversion_rate_source',
            ]);
        });

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn('reporting_timezone');
        });
    }

    private function backfillReliableOriginalCurrencies(): void
    {
        DB::table('connector_normalized_daily_performances')
            ->whereNull('original_currency')
            ->orderBy('id')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    $currency = $this->currencyForPerformanceRow($row);

                    if ($currency === null) {
                        continue;
                    }

                    DB::table('connector_normalized_daily_performances')
                        ->where('id', $row->id)
                        ->update(['original_currency' => $currency]);
                }
            });
    }

    private function currencyForPerformanceRow(object $row): ?string
    {
        $entityType = strtolower((string) $row->entity_type);
        $currency = match ($entityType) {
            'campaign' => DB::table('connector_normalized_campaigns')
                ->where('workspace_id', $row->workspace_id)
                ->where('provider', $row->provider)
                ->where('provider_campaign_id', $row->entity_id)
                ->value('currency'),
            'account' => DB::table('connector_normalized_marketing_accounts')
                ->where('workspace_id', $row->workspace_id)
                ->where('provider', $row->provider)
                ->where('provider_account_id', $row->entity_id)
                ->value('currency'),
            default => null,
        };

        return $this->normalizeCurrency($currency);
    }

    private function normalizeCurrency(mixed $currency): ?string
    {
        $currency = strtoupper(trim((string) $currency));

        return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : null;
    }
};
