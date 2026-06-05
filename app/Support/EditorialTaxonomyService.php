<?php

namespace App\Support;

use App\Models\TaxonomyItem;
use App\Models\TaxonomySet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EditorialTaxonomyService
{
    public function normalizeKey(string $value): string
    {
        return self::normalizeKeyStatic($value);
    }

    public static function normalizeKeyStatic(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;

        return trim($value, '_');
    }

    /**
     * @return Collection<int, TaxonomyItem>
     */
    public function activeItemsByTenantAndType(int $tenantId, string $type): Collection
    {
        $type = strtolower(trim($type));
        if (! in_array($type, TaxonomyItem::allowedTypes(), true)) {
            return collect();
        }

        $this->ensureDefaults($tenantId);
        $setIds = $this->assignedSetIdsForTenant($tenantId);

        return TaxonomyItem::query()
            ->whereIn('taxonomy_set_id', $setIds)
            ->where('type', $type)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, string>
     */
    public function activeItemMapByTenantAndType(int $tenantId, string $type): array
    {
        return $this->activeItemsByTenantAndType($tenantId, $type)
            ->mapWithKeys(fn (TaxonomyItem $item): array => [(string) $item->slug => (string) $item->name])
            ->all();
    }

    public function ensureDefaults(int $tenantId): void
    {
        DB::transaction(function () use ($tenantId): void {
            $defaultSet = TaxonomySet::query()->firstOrCreate(
                ['name' => 'PL Basis'],
                [
                    'description' => 'Default Argusly editorial taxonomy',
                    'is_default' => true,
                ]
            );

            if (! $defaultSet->is_default) {
                $defaultSet->is_default = true;
                $defaultSet->save();
            }

            foreach ($this->defaultItems() as $item) {
                TaxonomyItem::query()->firstOrCreate(
                    [
                        'taxonomy_set_id' => $defaultSet->id,
                        'slug' => $item['slug'],
                    ],
                    [
                        'type' => $item['type'],
                        'name' => $item['name'],
                        'parent_id' => null,
                        'is_active' => true,
                    ]
                );
            }

            if ($tenantId > 0) {
                $hasAssignment = DB::table('taxonomy_set_tenant')
                    ->where('tenant_id', $tenantId)
                    ->exists();

                if ($hasAssignment) {
                    return;
                }

                $defaultSetIds = TaxonomySet::query()
                    ->where('is_default', true)
                    ->pluck('id');

                foreach ($defaultSetIds as $setId) {
                    DB::table('taxonomy_set_tenant')->updateOrInsert(
                        [
                            'taxonomy_set_id' => (int) $setId,
                            'tenant_id' => $tenantId,
                        ],
                        [
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }
            }
        });
    }

    /**
     * @return array<int, int>
     */
    public function assignedSetIdsForTenant(int $tenantId): array
    {
        return DB::table('taxonomy_set_tenant')
            ->where('tenant_id', $tenantId)
            ->pluck('taxonomy_set_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{type:string,name:string,slug:string}>
     */
    private function defaultItems(): array
    {
        return [
            ['type' => TaxonomyItem::TYPE_INTENT, 'name' => 'Informational', 'slug' => 'informational'],
            ['type' => TaxonomyItem::TYPE_INTENT, 'name' => 'Educational', 'slug' => 'educational'],
            ['type' => TaxonomyItem::TYPE_INTENT, 'name' => 'Technical', 'slug' => 'technical'],
            ['type' => TaxonomyItem::TYPE_INTENT, 'name' => 'Commercial', 'slug' => 'commercial'],
            ['type' => TaxonomyItem::TYPE_AUDIENCE, 'name' => 'Developer', 'slug' => 'developer'],
            ['type' => TaxonomyItem::TYPE_AUDIENCE, 'name' => 'Tech Lead', 'slug' => 'tech_lead'],
            ['type' => TaxonomyItem::TYPE_AUDIENCE, 'name' => 'CTO', 'slug' => 'cto'],
            ['type' => TaxonomyItem::TYPE_AUDIENCE, 'name' => 'Marketer', 'slug' => 'marketer'],
            ['type' => TaxonomyItem::TYPE_AUDIENCE, 'name' => 'Founder', 'slug' => 'founder'],
            ['type' => TaxonomyItem::TYPE_AUDIENCE, 'name' => 'Operations', 'slug' => 'operations'],
        ];
    }
}
