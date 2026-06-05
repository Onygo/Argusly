<?php

namespace App\Support\Connectors;

/**
 * Value object representing connector capabilities.
 *
 * Capabilities declare what operations a connector supports. The publication
 * orchestration layer uses this to determine available actions and validate
 * operations before calling connector methods.
 *
 * ## Usage
 *
 * ```php
 * $caps = ConnectorCapabilities::full(); // All capabilities enabled
 * $caps = ConnectorCapabilities::readOnly(); // Only verification/health
 * $caps = ConnectorCapabilities::make()
 *     ->withCreate()
 *     ->withUpdate()
 *     ->withFeaturedImage();
 * ```
 */
final class ConnectorCapabilities
{
    public function __construct(
        // Core operations
        public readonly bool $supportsCreate = false,
        public readonly bool $supportsUpdate = false,
        public readonly bool $supportsDelete = false,
        public readonly bool $supportsScheduling = false,
        public readonly bool $supportsVerification = false,

        // Verification behavior
        public readonly bool $requiresStrictVerification = false,

        // Content features
        public readonly bool $supportsFeaturedImage = false,
        public readonly bool $supportsCategories = false,
        public readonly bool $supportsTags = false,
        public readonly bool $supportsSeoFields = false,
        public readonly bool $supportsMultipleLanguages = false,
        public readonly bool $supportsCustomFields = false,
        public readonly bool $supportsExcerpt = false,
        public readonly bool $supportsSlug = false,

        // Behavior
        public readonly bool $isAsyncOnly = false,
        public readonly bool $requiresAuthentication = true,

        /** @var array<string> Supported content types (post, page, article, etc.) */
        public readonly array $supportedContentTypes = ['post'],
    ) {}

    /**
     * Create a new capabilities builder.
     */
    public static function make(): self
    {
        return new self();
    }

    /**
     * Create capabilities with all features enabled.
     */
    public static function full(): self
    {
        return new self(
            supportsCreate: true,
            supportsUpdate: true,
            supportsDelete: true,
            supportsScheduling: true,
            supportsVerification: true,
            requiresStrictVerification: true,
            supportsFeaturedImage: true,
            supportsCategories: true,
            supportsTags: true,
            supportsSeoFields: true,
            supportsMultipleLanguages: true,
            supportsCustomFields: true,
            supportsExcerpt: true,
            supportsSlug: true,
            isAsyncOnly: false,
            requiresAuthentication: true,
            supportedContentTypes: ['post', 'page', 'article'],
        );
    }

    /**
     * Create read-only capabilities (verification and health only).
     */
    public static function readOnly(): self
    {
        return new self(
            supportsVerification: true,
        );
    }

    /**
     * Create capabilities for WordPress connector.
     */
    public static function wordpress(): self
    {
        return new self(
            supportsCreate: true,
            supportsUpdate: true,
            supportsDelete: true,
            supportsScheduling: true,
            supportsVerification: true,
            requiresStrictVerification: true,
            supportsFeaturedImage: true,
            supportsCategories: true,
            supportsTags: true,
            supportsSeoFields: true,
            supportsMultipleLanguages: true,
            supportsCustomFields: true,
            supportsExcerpt: true,
            supportsSlug: true,
            isAsyncOnly: false,
            requiresAuthentication: true,
            supportedContentTypes: ['post', 'page'],
        );
    }

    /**
     * Create capabilities for Laravel connector.
     */
    public static function laravel(): self
    {
        return new self(
            supportsCreate: true,
            supportsUpdate: true,
            supportsDelete: true,
            supportsScheduling: false, // Scheduling is orchestrated by Argusly
            supportsVerification: true,
            requiresStrictVerification: false,
            supportsFeaturedImage: true,
            supportsCategories: true,
            supportsTags: false, // Not currently supported
            supportsSeoFields: true,
            supportsMultipleLanguages: true,
            supportsCustomFields: false,
            supportsExcerpt: true,
            supportsSlug: true,
            isAsyncOnly: false, // Safe to execute through the generic queued publish job
            requiresAuthentication: true,
            supportedContentTypes: ['article'],
        );
    }

    // Builder methods

    public function withCreate(): self
    {
        return new self(...[...$this->toArray(), 'supportsCreate' => true]);
    }

    public function withUpdate(): self
    {
        return new self(...[...$this->toArray(), 'supportsUpdate' => true]);
    }

    public function withDelete(): self
    {
        return new self(...[...$this->toArray(), 'supportsDelete' => true]);
    }

    public function withScheduling(): self
    {
        return new self(...[...$this->toArray(), 'supportsScheduling' => true]);
    }

    public function withVerification(): self
    {
        return new self(...[...$this->toArray(), 'supportsVerification' => true]);
    }

    public function withFeaturedImage(): self
    {
        return new self(...[...$this->toArray(), 'supportsFeaturedImage' => true]);
    }

    public function withCategories(): self
    {
        return new self(...[...$this->toArray(), 'supportsCategories' => true]);
    }

    public function withTags(): self
    {
        return new self(...[...$this->toArray(), 'supportsTags' => true]);
    }

    public function withSeoFields(): self
    {
        return new self(...[...$this->toArray(), 'supportsSeoFields' => true]);
    }

    public function withMultipleLanguages(): self
    {
        return new self(...[...$this->toArray(), 'supportsMultipleLanguages' => true]);
    }

    public function asAsyncOnly(): self
    {
        return new self(...[...$this->toArray(), 'isAsyncOnly' => true]);
    }

    /**
     * @param array<string> $types
     */
    public function withContentTypes(array $types): self
    {
        return new self(...[...$this->toArray(), 'supportedContentTypes' => $types]);
    }

    // Query methods

    public function canPublish(): bool
    {
        return $this->supportsCreate;
    }

    public function canUpdate(): bool
    {
        return $this->supportsUpdate;
    }

    public function canDelete(): bool
    {
        return $this->supportsDelete;
    }

    public function canSchedule(): bool
    {
        return $this->supportsScheduling;
    }

    public function canVerify(): bool
    {
        return $this->supportsVerification;
    }

    public function supportsContentType(string $type): bool
    {
        return in_array($type, $this->supportedContentTypes, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'supportsCreate' => $this->supportsCreate,
            'supportsUpdate' => $this->supportsUpdate,
            'supportsDelete' => $this->supportsDelete,
            'supportsScheduling' => $this->supportsScheduling,
            'supportsVerification' => $this->supportsVerification,
            'requiresStrictVerification' => $this->requiresStrictVerification,
            'supportsFeaturedImage' => $this->supportsFeaturedImage,
            'supportsCategories' => $this->supportsCategories,
            'supportsTags' => $this->supportsTags,
            'supportsSeoFields' => $this->supportsSeoFields,
            'supportsMultipleLanguages' => $this->supportsMultipleLanguages,
            'supportsCustomFields' => $this->supportsCustomFields,
            'supportsExcerpt' => $this->supportsExcerpt,
            'supportsSlug' => $this->supportsSlug,
            'isAsyncOnly' => $this->isAsyncOnly,
            'requiresAuthentication' => $this->requiresAuthentication,
            'supportedContentTypes' => $this->supportedContentTypes,
        ];
    }
}
