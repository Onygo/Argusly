<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (! Schema::hasColumn('plans', 'has_required_onboarding')) {
                $table->boolean('has_required_onboarding')->default(false)->after('seat_limit');
            }

            if (! Schema::hasColumn('plans', 'onboarding_label')) {
                $table->string('onboarding_label', 120)->nullable()->after('has_required_onboarding');
            }

            if (! Schema::hasColumn('plans', 'onboarding_checkout_label')) {
                $table->string('onboarding_checkout_label', 120)->nullable()->after('onboarding_label');
            }

            if (! Schema::hasColumn('plans', 'onboarding_receipt_label')) {
                $table->string('onboarding_receipt_label', 120)->nullable()->after('onboarding_checkout_label');
            }

            if (! Schema::hasColumn('plans', 'onboarding_description')) {
                $table->text('onboarding_description')->nullable()->after('onboarding_receipt_label');
            }

            if (! Schema::hasColumn('plans', 'onboarding_fee_cents')) {
                $table->unsignedInteger('onboarding_fee_cents')->nullable()->after('onboarding_description');
            }

            if (! Schema::hasColumn('plans', 'onboarding_fee_currency')) {
                $table->string('onboarding_fee_currency', 8)->default('EUR')->after('onboarding_fee_cents');
            }

            if (! Schema::hasColumn('plans', 'onboarding_display_mode')) {
                $table->string('onboarding_display_mode', 64)->nullable()->after('onboarding_fee_currency');
            }

            if (! Schema::hasColumn('plans', 'onboarding_is_visible_public')) {
                $table->boolean('onboarding_is_visible_public')->default(true)->after('onboarding_display_mode');
            }

            if (! Schema::hasColumn('plans', 'onboarding_sort_order')) {
                $table->unsignedInteger('onboarding_sort_order')->default(0)->after('onboarding_is_visible_public');
            }

            if (! Schema::hasColumn('plans', 'onboarding_internal_notes')) {
                $table->text('onboarding_internal_notes')->nullable()->after('onboarding_sort_order');
            }

            if (! Schema::hasColumn('plans', 'onboarding_effective_from')) {
                $table->timestamp('onboarding_effective_from')->nullable()->after('onboarding_internal_notes');
            }
        });

        DB::table('plans')
            ->select([
                'id',
                'slug',
                'key',
                'price_cents',
                'monthly_price_cents',
                'limits',
            ])
            ->orderBy('created_at')
            ->chunkById(100, function ($plans): void {
                foreach ($plans as $plan) {
                    $limits = is_array($plan->limits) ? $plan->limits : json_decode((string) $plan->limits, true);
                    $limits = is_array($limits) ? $limits : [];
                    $slug = trim((string) ($plan->slug ?: $plan->key ?: ''));
                    $priceCents = (int) ($plan->price_cents ?? $plan->monthly_price_cents ?? 0);

                    $legacyRequired = array_key_exists('has_required_onboarding', $limits)
                        ? (bool) $limits['has_required_onboarding']
                        : false;
                    $legacyFee = array_key_exists('onboarding_fee_cents', $limits)
                        ? max(0, (int) $limits['onboarding_fee_cents'])
                        : null;
                    $legacyLabel = trim((string) ($limits['onboarding_label'] ?? ''));
                    $legacyDescription = trim((string) ($limits['onboarding_description'] ?? ''));

                    $defaultRequired = in_array($slug, ['starter', 'growth', 'scale'], true);
                    $defaultFee = match ($slug) {
                        'starter', 'growth' => 25000,
                        'scale' => 75000,
                        default => null,
                    };
                    $defaultMode = match ($slug) {
                        'scale' => 'implementation_onboarding',
                        'starter', 'growth' => 'guided_onboarding',
                        default => 'guided_onboarding',
                    };
                    $defaultLabel = match ($slug) {
                        'scale' => 'Implementation Onboarding',
                        'starter', 'growth' => 'Guided Onboarding',
                        default => null,
                    };
                    $defaultDescription = match ($slug) {
                        'starter' => 'Includes guided onboarding for workspace setup, core structure and your first workflow.',
                        'growth' => 'Includes guided onboarding for workspace setup, core structure and stronger publishing flow setup.',
                        'scale' => 'Includes implementation onboarding for structure, brand voice, team alignment and rollout support.',
                        default => null,
                    };

                    $required = $legacyRequired || ($defaultRequired && $priceCents > 0);
                    $fee = $legacyFee ?? ($required ? $defaultFee : null);
                    $label = $legacyLabel !== '' ? $legacyLabel : $defaultLabel;
                    $description = $legacyDescription !== '' ? $legacyDescription : $defaultDescription;
                    $displayMode = $legacyLabel !== ''
                        ? $this->displayModeFromLabel($legacyLabel)
                        : $defaultMode;

                    DB::table('plans')
                        ->where('id', $plan->id)
                        ->update([
                            'has_required_onboarding' => $required,
                            'onboarding_label' => $label,
                            'onboarding_checkout_label' => $label,
                            'onboarding_receipt_label' => $label,
                            'onboarding_description' => $description,
                            'onboarding_fee_cents' => $fee,
                            'onboarding_fee_currency' => 'EUR',
                            'onboarding_display_mode' => $displayMode,
                            'onboarding_is_visible_public' => $required,
                            'onboarding_sort_order' => 0,
                            'updated_at' => now(),
                        ]);
                }
            }, 'id');
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            foreach ([
                'has_required_onboarding',
                'onboarding_label',
                'onboarding_checkout_label',
                'onboarding_receipt_label',
                'onboarding_description',
                'onboarding_fee_cents',
                'onboarding_fee_currency',
                'onboarding_display_mode',
                'onboarding_is_visible_public',
                'onboarding_sort_order',
                'onboarding_internal_notes',
                'onboarding_effective_from',
            ] as $column) {
                if (Schema::hasColumn('plans', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function displayModeFromLabel(string $label): string
    {
        $normalized = strtolower(trim($label));

        return match (true) {
            str_contains($normalized, 'implementation') => 'implementation_onboarding',
            str_contains($normalized, 'launch') => 'launch_setup',
            default => 'guided_onboarding',
        };
    }
};
