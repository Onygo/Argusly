<?php

namespace App\Support\Publication;

use App\Enums\DestinationCapability;
use App\Support\Connectors\ConnectorCapabilities;

class PublicationDestinationCapabilities
{
    /**
     * @param  array<string, bool>  $capabilities
     */
    public function __construct(
        private readonly array $capabilities,
    ) {}

    public static function fromConnectorCapabilities(ConnectorCapabilities $capabilities): self
    {
        return new self([
            DestinationCapability::REMOTE_VERIFICATION->value => $capabilities->canVerify(),
            DestinationCapability::PREVIEW_URL->value => true,
            DestinationCapability::STATUS_SYNC->value => true,
            DestinationCapability::MARKDOWN_PUSH->value => $capabilities->canPublish(),
            DestinationCapability::SEO_META_SYNC->value => $capabilities->supportsSeoFields,
            DestinationCapability::SLUG_UPDATES->value => $capabilities->supportsSlug,
        ]);
    }

    /**
     * @param  array<int, DestinationCapability>  $capabilities
     */
    public static function only(array $capabilities): self
    {
        $items = [];

        foreach (DestinationCapability::cases() as $capability) {
            $items[$capability->value] = in_array($capability, $capabilities, true);
        }

        return new self($items);
    }

    public static function unsupported(): self
    {
        return new self(array_fill_keys(
            array_map(static fn (DestinationCapability $capability): string => $capability->value, DestinationCapability::cases()),
            false
        ));
    }

    public function supports(DestinationCapability $capability): bool
    {
        return (bool) ($this->capabilities[$capability->value] ?? false);
    }

    /**
     * @return array<int, array{key:string,label:string,supported:bool}>
     */
    public function summary(): array
    {
        return array_map(function (DestinationCapability $capability): array {
            return [
                'key' => $capability->value,
                'label' => $capability->label(),
                'supported' => $this->supports($capability),
            ];
        }, DestinationCapability::cases());
    }
}
