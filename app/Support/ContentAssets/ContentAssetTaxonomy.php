<?php

namespace App\Support\ContentAssets;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ContentAssetTaxonomy
{
    /**
     * @return array<string,mixed>
     */
    public static function definition(?string $type): array
    {
        $type = self::normalizeType($type);
        $definition = config("content_assets.types.{$type}");

        if (is_array($definition)) {
            return ['type' => $type] + $definition;
        }

        return [
            'type' => $type,
            'label' => Str::headline($type),
            'badge' => Str::upper(Str::limit(str_replace('_', ' ', $type), 12, '')),
            'description' => 'Configurable content asset.',
            'category' => 'content',
            'purpose' => 'primary_content',
            'icon' => 'box',
            'color' => 'slate',
            'group' => 'content_creation',
            'publishable' => true,
        ];
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    public static function typeOptions(): array
    {
        return collect(config('content_assets.types', []))
            ->map(fn (array $definition, string $type): array => [
                'value' => $type,
                'label' => (string) ($definition['label'] ?? Str::headline($type)),
            ])
            ->sortBy('label')
            ->values()
            ->all();
    }

    /**
     * @return array<string,string>
     */
    public static function purposeLabels(): array
    {
        return [
            'primary_content' => 'Primary Content',
            'distribution_content' => 'Distribution Content',
            'seo_content' => 'SEO Content',
            'aeo_content' => 'AEO Content',
            'knowledge_content' => 'Knowledge Content',
            'conversion_content' => 'Conversion Content',
            'campaign_content' => 'Campaign Content',
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function workflowStateLabels(): array
    {
        return [
            'draft' => 'Draft',
            'generated' => 'Generated',
            'ready' => 'Ready',
            'review_required' => 'Review Required',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'archived' => 'Archived',
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function publicationStateLabels(): array
    {
        return [
            'not_publishable' => 'Not Publishable',
            'ready_to_publish' => 'Ready to Publish',
            'scheduled' => 'Scheduled',
            'published' => 'Published',
            'unpublished' => 'Unpublished',
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function distributionStateLabels(): array
    {
        return [
            'not_distributed' => 'Not Distributed',
            'distribution_pending' => 'Distribution Pending',
            'distributed' => 'Distributed',
            'failed' => 'Failed',
        ];
    }

    public static function purposeLabel(string $purpose): string
    {
        return self::purposeLabels()[$purpose] ?? Str::headline($purpose);
    }

    public static function workflowStateLabel(string $state): string
    {
        return self::workflowStateLabels()[$state] ?? Str::headline($state);
    }

    public static function publicationStateLabel(string $state): string
    {
        return self::publicationStateLabels()[$state] ?? Str::headline($state);
    }

    public static function distributionStateLabel(string $state): string
    {
        return self::distributionStateLabels()[$state] ?? Str::headline($state);
    }

    public static function typeBadgeClasses(string $color): string
    {
        return match ($color) {
            'amber' => 'border-amber-200 bg-amber-50 text-amber-800',
            'blue' => 'border-blue-200 bg-blue-50 text-blue-800',
            'cyan' => 'border-cyan-200 bg-cyan-50 text-cyan-800',
            'emerald' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
            'fuchsia' => 'border-fuchsia-200 bg-fuchsia-50 text-fuchsia-800',
            'indigo' => 'border-indigo-200 bg-indigo-50 text-indigo-800',
            'rose' => 'border-rose-200 bg-rose-50 text-rose-800',
            'sky' => 'border-sky-200 bg-sky-50 text-sky-800',
            'teal' => 'border-teal-200 bg-teal-50 text-teal-800',
            'violet' => 'border-violet-200 bg-violet-50 text-violet-800',
            default => 'border-slate-200 bg-slate-50 text-slate-700',
        };
    }

    public static function stateBadgeClasses(string $state): string
    {
        return match ($state) {
            'approved', 'published', 'distributed', 'ready', 'ready_to_publish' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
            'review_required', 'distribution_pending', 'scheduled', 'generated' => 'border-amber-200 bg-amber-50 text-amber-800',
            'rejected', 'failed' => 'border-rose-200 bg-rose-50 text-rose-800',
            'archived', 'unpublished', 'not_publishable', 'not_distributed' => 'border-slate-200 bg-slate-50 text-slate-700',
            default => 'border-border bg-surfaceSubtle text-textSecondary',
        };
    }

    private static function normalizeType(?string $type): string
    {
        $type = trim((string) $type);

        return $type !== '' ? $type : 'article';
    }

    /**
     * @param  array<string,mixed>  $definition
     */
    public static function value(array $definition, string $key, mixed $default = null): mixed
    {
        return Arr::get($definition, $key, $default);
    }
}
