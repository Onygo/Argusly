<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organizations')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table): void {
            if (! Schema::hasColumn('organizations', 'legal_name')) {
                $table->string('legal_name')->nullable()->after('name');
            }

            if (! Schema::hasColumn('organizations', 'billing_email')) {
                $table->string('billing_email')->nullable()->after('billing_company_name');
            }

            if (! Schema::hasColumn('organizations', 'vat_id')) {
                $table->string('vat_id', 64)->nullable()->after('billing_vat_number');
            }

            if (! Schema::hasColumn('organizations', 'billing_address')) {
                $table->json('billing_address')->nullable()->after('billing_kvk_number');
            }
        });

        DB::table('organizations')
            ->select([
                'id',
                'name',
                'legal_name',
                'billing_company_name',
                'billing_vat_number',
                'vat_id',
                'billing_address',
                'billing_address_line1',
                'billing_address_line2',
                'billing_postal_code',
                'billing_city',
                'billing_country_code',
            ])
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $updates = [];

                    if (trim((string) ($row->legal_name ?? '')) === '') {
                        $updates['legal_name'] = (string) ($row->billing_company_name ?: $row->name);
                    }

                    if (trim((string) ($row->vat_id ?? '')) === '') {
                        $updates['vat_id'] = (string) ($row->billing_vat_number ?: '');
                    }

                    if ($row->billing_address === null) {
                        $address = [
                            'line1' => $row->billing_address_line1,
                            'line2' => $row->billing_address_line2,
                            'postal_code' => $row->billing_postal_code,
                            'city' => $row->billing_city,
                            'country_code' => $row->billing_country_code,
                        ];

                        $hasAddressData = false;
                        foreach ($address as $value) {
                            if (trim((string) ($value ?? '')) !== '') {
                                $hasAddressData = true;
                                break;
                            }
                        }

                        if ($hasAddressData) {
                            $updates['billing_address'] = json_encode($address, JSON_UNESCAPED_UNICODE);
                        }
                    }

                    if ($updates === []) {
                        continue;
                    }

                    DB::table('organizations')
                        ->where('id', $row->id)
                        ->update($updates);
                }
            }, 'id');
    }

    public function down(): void
    {
        if (! Schema::hasTable('organizations')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table): void {
            foreach (['legal_name', 'billing_email', 'vat_id', 'billing_address'] as $column) {
                if (Schema::hasColumn('organizations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

