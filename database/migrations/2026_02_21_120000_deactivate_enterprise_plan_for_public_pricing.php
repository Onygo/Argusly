<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plans')) {
            return;
        }

        $enterprisePlans = DB::table('plans')
            ->select('id')
            ->where(function ($query): void {
                $query->where('slug', 'enterprise')->orWhere('key', 'enterprise');
            })
            ->get();

        foreach ($enterprisePlans as $enterprisePlan) {
            $hasLiveSubscriptions = Schema::hasTable('subscriptions')
                ? DB::table('subscriptions')
                    ->where('plan_id', $enterprisePlan->id)
                    ->whereIn('status', ['active', 'trialing', 'pending_mandate', 'past_due', 'suspended'])
                    ->exists()
                : false;

            $update = [
                'is_popular' => false,
                'cta_label' => 'Contact us',
                'cta_href' => '/contact?subject=maatwerk-enterprise#contact-form',
                'updated_at' => now(),
            ];

            if (! $hasLiveSubscriptions && Schema::hasColumn('plans', 'is_active')) {
                $update['is_active'] = false;
            }

            DB::table('plans')->where('id', $enterprisePlan->id)->update($update);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('plans')) {
            return;
        }

        DB::table('plans')
            ->where(function ($query): void {
                $query->where('slug', 'enterprise')->orWhere('key', 'enterprise');
            })
            ->update([
                'is_active' => true,
                'cta_href' => '/company/contact?topic=Enterprise%20pricing&source=pricing&cta=Contact%20us#contact-form',
                'updated_at' => now(),
            ]);
    }
};
