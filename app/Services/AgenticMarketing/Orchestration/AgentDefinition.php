<?php

namespace App\Services\AgenticMarketing\Orchestration;

class AgentDefinition
{
    /**
     * @param  array<int,string>  $capabilities
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $role,
        public readonly array $capabilities,
        public readonly string $serviceClass,
        public readonly bool $enabled = true,
    ) {}

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'role' => $this->role,
            'capabilities' => $this->capabilities,
            'service_class' => $this->serviceClass,
            'enabled' => $this->enabled,
        ];
    }
}
