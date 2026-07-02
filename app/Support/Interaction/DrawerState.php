<?php

namespace App\Support\Interaction;

use InvalidArgumentException;

final class DrawerState
{
    public const MODE_INSPECT = 'inspect';
    public const MODE_PREVIEW = 'preview';
    public const MODE_READONLY = 'readonly';
    public const MODE_EDIT = 'edit';

    public const MODES = [
        self::MODE_INSPECT,
        self::MODE_PREVIEW,
        self::MODE_READONLY,
        self::MODE_EDIT,
    ];

    public function __construct(
        public readonly string $mode = self::MODE_INSPECT,
        public readonly bool $open = false,
        public readonly bool $loading = false,
        public readonly bool $empty = false,
        public readonly bool $error = false,
        public readonly ?string $message = null,
        public readonly array $metadata = [],
    ) {
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException(sprintf('Drawer mode [%s] is not supported.', $mode));
        }
    }

    public static function closed(string $mode = self::MODE_INSPECT, array $metadata = []): self
    {
        return new self(mode: $mode, metadata: $metadata);
    }

    public static function open(string $mode = self::MODE_INSPECT, array $metadata = []): self
    {
        return new self(mode: $mode, open: true, metadata: $metadata);
    }

    public static function loading(string $mode = self::MODE_INSPECT, ?string $message = null, array $metadata = []): self
    {
        return new self(mode: $mode, open: true, loading: true, message: $message, metadata: $metadata);
    }

    public static function empty(string $mode = self::MODE_INSPECT, ?string $message = null, array $metadata = []): self
    {
        return new self(mode: $mode, open: true, empty: true, message: $message, metadata: $metadata);
    }

    public static function error(string $mode = self::MODE_INSPECT, ?string $message = null, array $metadata = []): self
    {
        return new self(mode: $mode, open: true, error: true, message: $message, metadata: $metadata);
    }

    public function withMode(string $mode): self
    {
        return new self(
            mode: $mode,
            open: $this->open,
            loading: $this->loading,
            empty: $this->empty,
            error: $this->error,
            message: $this->message,
            metadata: $this->metadata,
        );
    }

    public function isInteractive(): bool
    {
        return $this->open && ! $this->loading && ! $this->empty && ! $this->error;
    }

    public function canEdit(): bool
    {
        return $this->mode === self::MODE_EDIT && ! $this->loading && ! $this->error;
    }

    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'open' => $this->open,
            'loading' => $this->loading,
            'empty' => $this->empty,
            'error' => $this->error,
            'message' => $this->message,
            'interactive' => $this->isInteractive(),
            'can_edit' => $this->canEdit(),
            'metadata' => $this->metadata,
        ];
    }
}
