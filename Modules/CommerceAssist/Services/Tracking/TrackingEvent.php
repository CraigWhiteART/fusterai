<?php

namespace Modules\CommerceAssist\Services\Tracking;

use Carbon\CarbonImmutable;
use Throwable;

class TrackingEvent
{
    public function __construct(
        public readonly ?CarbonImmutable $occurredAt,
        public readonly ?string $description,
        public readonly ?string $location = null,
        public readonly ?string $stage = null,
        public readonly bool $lastMile = false,
    ) {}

    public static function make(mixed $occurredAt, ?string $description, ?string $location = null, ?string $stage = null, bool $lastMile = false): self
    {
        return new self(self::parseDate($occurredAt), self::clean($description), self::clean($location), $stage, $lastMile);
    }

    public static function parseDate(mixed $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'occurred_at' => $this->occurredAt?->toIso8601String(),
            'description' => $this->description,
            'location' => $this->location,
            'stage' => $this->stage,
            'last_mile' => $this->lastMile,
        ];
    }

    private static function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
