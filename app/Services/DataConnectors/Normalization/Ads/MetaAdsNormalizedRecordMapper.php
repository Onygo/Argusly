<?php

namespace App\Services\DataConnectors\Normalization\Ads;

class MetaAdsNormalizedRecordMapper extends AbstractAdsNormalizedRecordMapper
{
    public function provider(): string
    {
        return 'meta_ads';
    }
}
