<?php

namespace App\Services\DataConnectors;

use Closure;

class ConnectorSyncPipeline
{
    /**
     * @var list<callable(ConnectorSyncContext): void>
     */
    private array $beforeStages = [];

    /**
     * @var list<callable(ConnectorSyncContext, ConnectorSyncResult): void>
     */
    private array $afterStages = [];

    public function before(callable $stage): self
    {
        $this->beforeStages[] = $stage;

        return $this;
    }

    public function after(callable $stage): self
    {
        $this->afterStages[] = $stage;

        return $this;
    }

    public function run(ConnectorSyncContext $context, Closure $sync): ConnectorSyncResult
    {
        foreach ($this->beforeStages as $stage) {
            $stage($context);
        }

        $result = $sync($context);

        foreach ($this->afterStages as $stage) {
            $stage($context, $result);
        }

        return $result;
    }
}
