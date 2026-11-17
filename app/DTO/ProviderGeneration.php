<?php

namespace App\DTO;

use App\Enums\GenerationStatus;
use Carbon\CarbonImmutable;

/**
 * A Pixelforge generation object, as returned by the API or carried in a webhook.
 */
final readonly class ProviderGeneration
{
    /**
     * @param  array<string, numeric-string>  $rates
     * @param  array<string, int>|null  $usage
     */
    public function __construct(
        public string $id,
        public GenerationStatus $status,
        public ?string $localId,
        public array $rates,
        public ?array $usage,
        public ?string $imageUrl,
        public ?CarbonImmutable $imageUrlExpiresAt,
        public ?CarbonImmutable $completedAt,
        public ?string $errorCode,
        public ?string $errorMessage,
    ) {}

    /**
     * @param  array<string, mixed>  $generation
     */
    public static function fromArray(array $generation): self
    {
        return new self(
            id: $generation['id'],
            status: GenerationStatus::from($generation['status']),
            localId: $generation['metadata']['local_id'] ?? null,
            rates: $generation['rates'],
            usage: $generation['usage'],
            imageUrl: $generation['image_url'],
            imageUrlExpiresAt: self::parseTime($generation['image_url_expires_at']),
            completedAt: self::parseTime($generation['completed_at']),
            errorCode: $generation['error']['code'] ?? null,
            errorMessage: $generation['error']['message'] ?? null,
        );
    }

    private static function parseTime(?string $timestamp): ?CarbonImmutable
    {
        return $timestamp === null ? null : CarbonImmutable::parse($timestamp)->utc();
    }
}
