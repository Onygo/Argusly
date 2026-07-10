<?php

namespace App\Services\DataConnectors\Normalization;

use App\Contracts\Connectors\Normalization\NormalizedRecordMapper;
use App\Services\DataConnectors\Normalization\Ads\GoogleAdsNormalizedRecordMapper;
use App\Services\DataConnectors\Normalization\Ads\MetaAdsNormalizedRecordMapper;
use App\Services\DataConnectors\Normalization\Ads\MicrosoftAdsNormalizedRecordMapper;
use App\Services\DataConnectors\Normalization\Crm\HubSpotNormalizedRecordMapper;
use App\Services\DataConnectors\Normalization\Crm\PipedriveNormalizedRecordMapper;
use App\Services\DataConnectors\Normalization\Crm\SalesforceNormalizedRecordMapper;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

class NormalizedRecordMapperResolver
{
    /**
     * @var array<string, class-string<NormalizedRecordMapper>>
     */
    private array $mappers = [
        'google_ads' => GoogleAdsNormalizedRecordMapper::class,
        'microsoft_ads' => MicrosoftAdsNormalizedRecordMapper::class,
        'meta_ads' => MetaAdsNormalizedRecordMapper::class,
        'hubspot' => HubSpotNormalizedRecordMapper::class,
        'salesforce' => SalesforceNormalizedRecordMapper::class,
        'pipedrive' => PipedriveNormalizedRecordMapper::class,
    ];

    public function __construct(private readonly Container $container)
    {
    }

    public function has(string $provider): bool
    {
        return isset($this->mappers[$provider]);
    }

    public function resolve(string $provider): NormalizedRecordMapper
    {
        $mapperClass = $this->mappers[$provider] ?? null;

        if ($mapperClass === null) {
            throw new InvalidArgumentException("No normalized record mapper is configured for provider [{$provider}].");
        }

        return $this->container->make($mapperClass);
    }
}
