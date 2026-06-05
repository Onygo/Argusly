<?php

namespace App\View\Presenters;

use App\Enums\ContentDestinationType;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentPublication;
use App\Services\Publication\PublicationDestinationDriverResolver;

class PublicationDestinationPresenter
{
    public function __construct(
        private readonly ?ContentDestination $destination,
        private readonly ?ContentPublication $publication = null,
        private readonly ?Content $content = null,
        private readonly ?PublicationDestinationDriverResolver $resolver = null,
    ) {}

    public static function for(?ContentDestination $destination, ?ContentPublication $publication = null, ?Content $content = null): self
    {
        return new self($destination, $publication, $content, app(PublicationDestinationDriverResolver::class));
    }

    public function type(): ?string
    {
        return ContentDestinationType::normalize($this->destination?->rawTypeValue());
    }

    public function label(): string
    {
        if (! $this->destination) {
            return 'Unknown destination';
        }

        return $this->driver()?->label() ?? ContentDestinationType::label($this->type());
    }

    public function icon(): string
    {
        return $this->driver()?->icon() ?? 'triangle-alert';
    }

    public function statusLabel(): string
    {
        return $this->destination ? $this->label().' Status' : 'Unknown destination';
    }

    public function republishLabel(): string
    {
        return match ($this->type()) {
            ContentDestinationType::WORDPRESS->value => 'Republish to WordPress',
            ContentDestinationType::LARAVEL->value => 'Republish to Laravel',
            ContentDestinationType::API->value => 'Republish to API',
            default => 'Republish',
        };
    }

    public function openRemoteLabel(): string
    {
        return match ($this->type()) {
            ContentDestinationType::WORDPRESS->value => 'Open in WordPress',
            ContentDestinationType::LARAVEL->value => 'Open on site',
            ContentDestinationType::API->value => 'Inspect endpoint',
            default => 'Open destination',
        };
    }

    public function verifyLabel(): string
    {
        return match ($this->type()) {
            ContentDestinationType::WORDPRESS->value => 'Verify Remote Exists',
            ContentDestinationType::LARAVEL->value => 'Verify route exists',
            ContentDestinationType::API->value => 'Verify destination response',
            default => 'Unsupported destination',
        };
    }

    /**
     * @return array<int, array{key:string,label:string,supported:bool}>
     */
    public function capabilitySummary(): array
    {
        return $this->driver()?->capabilities()->summary() ?? [];
    }

    public function supportsLaravelConfig(): bool
    {
        return $this->type() === ContentDestinationType::LARAVEL->value;
    }

    public function supportsConnectionTest(): bool
    {
        return $this->supportsLaravelConfig();
    }

    public function unsupportedMessage(): ?string
    {
        return $this->destination ? null : 'Unsupported destination';
    }

    private function driver()
    {
        if (! $this->destination || ! $this->resolver) {
            return null;
        }

        try {
            return $this->resolver->resolveForDestination($this->destination);
        } catch (\RuntimeException) {
            return null;
        }
    }
}
