<?php

namespace App\Console\Commands;

use App\Models\CreditPack;
use App\Models\Plan;
use App\Services\MarketingPricingService;
use App\Services\SiteSettingsService;
use App\Support\LocalizedMarketingUrl;
use Database\Seeders\MarketingPricingPageSeeder;
use Illuminate\Console\Command;

class MarketingRepairPricingPageCommand extends Command
{
    protected $signature = 'marketing:repair-pricing-page';

    protected $description = 'Repair pricing plans, localized pricing content, and public pricing cache state.';

    public function handle(MarketingPricingService $pricing, SiteSettingsService $settings): int
    {
        $this->callSilent('db:seed', ['--class' => MarketingPricingPageSeeder::class, '--force' => true]);

        $pricing->clearCaches();
        $settings->clearCache('marketing_pricing_page');
        $settings->clearCache('pricing_page_content');

        $plans = Plan::query()
            ->whereIn('slug', ['creator', 'growth', 'scale', 'enterprise'])
            ->orderBy('sort_order')
            ->get(['slug', 'name', 'price_monthly_cents', 'included_credits_per_interval', 'billing_type', 'is_popular', 'is_active']);

        $packs = CreditPack::query()
            ->whereIn('key', ['pack_100', 'pack_500', 'pack_1000'])
            ->orderBy('credits_amount')
            ->get(['key', 'credits_amount', 'price_cents', 'expires_in_months', 'is_active']);

        $routes = [
            'en' => LocalizedMarketingUrl::route('pricing', [], 'en', false),
            'nl' => LocalizedMarketingUrl::route('pricing', [], 'nl', false),
        ];

        $this->info('Pricing repair completed.');
        $this->newLine();

        $this->table(
            ['Plan', 'Name', 'Monthly', 'Credits', 'Billing', 'Popular', 'Active'],
            $plans->map(fn (Plan $plan): array => [
                (string) $plan->slug,
                (string) $plan->name,
                $plan->price_monthly_cents === null ? 'custom' : '€' . number_format(((int) $plan->price_monthly_cents) / 100, 0),
                (string) (int) $plan->included_credits_per_interval,
                (string) $plan->billing_type,
                $plan->is_popular ? 'yes' : 'no',
                $plan->is_active ? 'yes' : 'no',
            ])->all()
        );

        $this->newLine();

        $this->table(
            ['Pack', 'Credits', 'Price', 'Valid months', 'Active'],
            $packs->map(fn (CreditPack $pack): array => [
                (string) $pack->key,
                (string) (int) $pack->credits_amount,
                '€' . number_format(((int) $pack->price_cents) / 100, 0),
                $pack->expires_in_months === null ? 'unlimited' : (string) (int) $pack->expires_in_months,
                $pack->is_active ? 'yes' : 'no',
            ])->all()
        );

        $this->newLine();
        $this->line('Localized routes:');
        foreach ($routes as $locale => $path) {
            $this->line(sprintf('  %s: %s', strtoupper($locale), $path));
        }

        $this->line(sprintf('Localized content key present: %s', $settings->get('marketing_pricing_page') ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
