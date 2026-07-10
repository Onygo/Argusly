<?php

namespace App\Services\DataConnectors\Normalization\Ads;

class GoogleAdsNormalizedRecordMapper extends AbstractAdsNormalizedRecordMapper
{
    public function provider(): string
    {
        return 'google_ads';
    }
}
