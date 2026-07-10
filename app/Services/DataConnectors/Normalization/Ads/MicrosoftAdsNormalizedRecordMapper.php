<?php

namespace App\Services\DataConnectors\Normalization\Ads;

class MicrosoftAdsNormalizedRecordMapper extends AbstractAdsNormalizedRecordMapper
{
    public function provider(): string
    {
        return 'microsoft_ads';
    }
}
