<?php

namespace App\Services\CompanyIntelligence;

use App\DTO\CompanyIntelligence\CompanyIntelligenceProfileData;
use App\Models\CompanyIntelligenceProfile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class CompanyIntelligenceNormalizer
{
    private const LIST_FIELDS = [
        'products_services',
        'regions',
        'locales',
        'icps',
        'personas',
        'buyer_roles',
        'pain_points',
        'objections',
        'buying_triggers',
        'funnel_stages',
        'banned_phrases',
        'messaging_rules',
        'brand_differentiators',
        'proof_points',
        'primary_topics',
        'authority_areas',
        'target_entities',
        'strategic_keywords',
        'query_intents',
        'direct_competitors',
        'indirect_competitors',
        'aspirational_competitors',
    ];

    /**
     * @param array<string,mixed>|CompanyIntelligenceProfile $source
     */
    public function normalize(array|CompanyIntelligenceProfile $source): CompanyIntelligenceProfileData
    {
        $data = $source instanceof CompanyIntelligenceProfile ? $source->attributesToArray() : $source;

        foreach (self::LIST_FIELDS as $field) {
            $data[$field] = $this->normalizeList($data[$field] ?? []);
        }

        $payload = [
            'schema_version' => 'company_intelligence.v1',
            'business' => [
                'company_name' => $this->text($data['company_name'] ?? ''),
                'company_description' => $this->text($data['company_description'] ?? ''),
                'market_category' => $this->text($data['market_category'] ?? ''),
                'positioning' => $this->text($data['positioning'] ?? ''),
                'uvp' => $this->text($data['uvp'] ?? ''),
                'products_services' => $data['products_services'],
                'pricing_model' => $this->text($data['pricing_model'] ?? ''),
                'regions' => $data['regions'],
                'locales' => $data['locales'],
            ],
            'audience' => [
                'icps' => $data['icps'],
                'personas' => $data['personas'],
                'buyer_roles' => $data['buyer_roles'],
                'pain_points' => $data['pain_points'],
                'objections' => $data['objections'],
                'buying_triggers' => $data['buying_triggers'],
                'funnel_stages' => $data['funnel_stages'],
            ],
            'brand' => [
                'tone_of_voice' => $this->text($data['tone_of_voice'] ?? ''),
                'banned_phrases' => $data['banned_phrases'],
                'messaging_rules' => $data['messaging_rules'],
                'brand_differentiators' => $data['brand_differentiators'],
                'proof_points' => $data['proof_points'],
            ],
            'seo_aeo' => [
                'primary_topics' => $data['primary_topics'],
                'authority_areas' => $data['authority_areas'],
                'target_entities' => $data['target_entities'],
                'strategic_keywords' => $data['strategic_keywords'],
                'query_intents' => $data['query_intents'],
            ],
            'competitors' => [
                'direct' => $data['direct_competitors'],
                'indirect' => $data['indirect_competitors'],
                'aspirational' => $data['aspirational_competitors'],
            ],
            'metadata' => [
                'brand_key' => $this->text($data['brand_key'] ?? 'primary'),
                'source_type' => $this->text($data['source_type'] ?? 'manual'),
                'status' => $this->text($data['status'] ?? CompanyIntelligenceProfile::STATUS_ACTIVE),
                'is_default' => (bool) ($data['is_default'] ?? false),
            ],
        ];

        $payload = $this->prune($payload);
        $breakdown = $this->completenessBreakdown($payload);
        $score = (int) round(collect($breakdown)->avg('score') ?? 0);
        $embeddingText = $this->embeddingText($payload);
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return new CompanyIntelligenceProfileData(
            payload: $payload,
            payloadHash: $hash,
            completenessScore: max(0, min(100, $score)),
            completenessBreakdown: $breakdown,
            embeddingText: $embeddingText,
        );
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function persistencePayload(array $input): array
    {
        foreach (self::LIST_FIELDS as $field) {
            if (array_key_exists($field, $input)) {
                $input[$field] = $this->normalizeList($input[$field]);
            }
        }

        $normalized = $this->normalize($input);

        return array_merge($input, [
            'normalized_payload' => $normalized->payload,
            'normalized_payload_hash' => $normalized->payloadHash,
            'completeness_score' => $normalized->completenessScore,
            'completeness_breakdown' => $normalized->completenessBreakdown,
            'embedding_status' => $normalized->completenessScore >= 60
                ? CompanyIntelligenceProfile::EMBEDDING_READY
                : CompanyIntelligenceProfile::EMBEDDING_NOT_READY,
            'embedding_payload_hash' => $normalized->payloadHash,
            'embedding_vector' => null,
        ]);
    }

    /**
     * @return array<int,string>
     */
    private function normalizeList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = preg_split('/\r\n|\r|\n|,/', $value) ?: [];
            }
        }

        return collect(Arr::wrap($value))
            ->flatten()
            ->map(fn (mixed $item): string => $this->text($item))
            ->filter()
            ->unique(fn (string $item): string => Str::lower($item))
            ->values()
            ->all();
    }

    private function text(mixed $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function prune(array $payload): array
    {
        return collect($payload)
            ->map(function (mixed $value): mixed {
                if (is_array($value)) {
                    return $this->prune($value);
                }

                return $value;
            })
            ->reject(fn (mixed $value): bool => $value === '' || $value === [] || $value === null)
            ->all();
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,array{score:int,present:int,total:int}>
     */
    private function completenessBreakdown(array $payload): array
    {
        $requirements = [
            'business' => ['company_name', 'company_description', 'market_category', 'positioning', 'uvp', 'products_services', 'regions', 'locales'],
            'audience' => ['icps', 'personas', 'buyer_roles', 'pain_points', 'objections', 'buying_triggers', 'funnel_stages'],
            'brand' => ['tone_of_voice', 'banned_phrases', 'messaging_rules', 'brand_differentiators', 'proof_points'],
            'seo_aeo' => ['primary_topics', 'authority_areas', 'target_entities', 'strategic_keywords', 'query_intents'],
            'competitors' => ['direct', 'indirect', 'aspirational'],
        ];

        return collect($requirements)
            ->mapWithKeys(function (array $fields, string $section) use ($payload): array {
                $present = collect($fields)->filter(fn (string $field): bool => filled(data_get($payload, $section . '.' . $field)))->count();
                $total = count($fields);

                return [$section => [
                    'score' => (int) round(($present / max(1, $total)) * 100),
                    'present' => $present,
                    'total' => $total,
                ]];
            })
            ->all();
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function embeddingText(array $payload): string
    {
        $lines = [];
        foreach (Arr::dot($payload) as $key => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $lines[] = str_replace('.', ' ', (string) $key) . ': ' . trim((string) $value);
            }
        }

        return implode("\n", $lines);
    }
}
