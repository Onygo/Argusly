<?php

namespace App\Services\DataConnectors;

class ConnectorSyncCheckpoint
{
    public function advance(ConnectorSyncContext $context, ConnectorSyncCursor $cursor): void
    {
        $payload = $cursor->toArray();

        $context->plan->dataset->forceFill([
            'cursor_json' => $payload,
        ])->save();

        $context->run->forceFill([
            'cursor_after_json' => $payload,
        ])->save();
    }
}
