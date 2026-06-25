<?php

namespace App\Services\EmailMarketing;

use App\Enums\EmailMarketingProvider;
use App\Models\EmailMarketingConnection;

class EmailMarketingProviderRegistry
{
    public function __construct(
        private readonly Providers\DmtEmailMarketingProvider $dmt,
    ) {}

    public function forConnection(EmailMarketingConnection $connection): EmailMarketingProviderClient
    {
        return match ($connection->provider) {
            EmailMarketingProvider::DMT => $this->dmt,
            EmailMarketingProvider::MAILCHIMP, EmailMarketingProvider::MAILJET => throw new EmailMarketingProviderException(
                $connection->provider->label().' is not implemented yet.'
            ),
        };
    }
}
