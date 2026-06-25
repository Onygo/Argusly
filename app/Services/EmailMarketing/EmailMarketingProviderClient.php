<?php

namespace App\Services\EmailMarketing;

use App\Models\EmailMarketingConnection;

interface EmailMarketingProviderClient
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function createDraft(EmailMarketingConnection $connection, array $payload, string $idempotencyKey): EmailMarketingProviderResult;
}
