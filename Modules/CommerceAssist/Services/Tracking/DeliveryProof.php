<?php

namespace Modules\CommerceAssist\Services\Tracking;

/**
 * What the carrier says it did with the parcel at the door.
 *
 * This is the cheap half of proof-of-delivery: sub-statuses like
 * "Sign by customer" or "Delivered to the front door" answer most
 * delivered-not-received tickets without touching a carrier website.
 * A signature image or photo still lives with the last-mile carrier.
 */
class DeliveryProof
{
    public const TYPE_SIGNATURE = 'signature';

    public const TYPE_HANDED_OVER = 'handed_over';

    public const TYPE_LEFT_AT_LOCATION = 'left_at_location';

    public const TYPE_COLLECTED = 'collected';

    public const TYPE_UNSPECIFIED = 'unspecified';

    public function __construct(
        public readonly string $type,
        public readonly ?string $detail = null,
        public readonly ?string $location = null,
    ) {}

    /**
     * Derive the proof type from the carrier's own words. Only ever called for
     * shipments the carrier already reports as delivered.
     *
     * @param  list<string|null>  $phrases
     */
    public static function fromPhrases(array $phrases, ?string $location = null): self
    {
        $detail = null;
        foreach ($phrases as $phrase) {
            $phrase = trim((string) $phrase);
            if ($phrase !== '' && $detail === null) {
                $detail = $phrase;
            }
        }

        $blob = strtolower(implode(' ', array_filter(array_map('strval', $phrases))));

        $type = match (true) {
            self::matches($blob, ['signed', 'signature', 'sign by', 'sign-by']) => self::TYPE_SIGNATURE,
            self::matches($blob, ['picked up', 'collected', 'pickup point', 'collection point', 'parcel locker']) => self::TYPE_COLLECTED,
            self::matches($blob, ['front door', 'left at', 'left in', 'safe place', 'safe drop', 'mailbox', 'letterbox', 'porch', 'garage', 'rear of property']) => self::TYPE_LEFT_AT_LOCATION,
            self::matches($blob, ['handed to', 'received by', 'in person', 'to resident', 'to recipient', 'reception', 'neighbour', 'neighbor']) => self::TYPE_HANDED_OVER,
            default => self::TYPE_UNSPECIFIED,
        };

        return new self($type, $detail, $location);
    }

    /** True when the carrier recorded a person accepting the parcel. */
    public function isAttributable(): bool
    {
        return in_array($this->type, [self::TYPE_SIGNATURE, self::TYPE_HANDED_OVER, self::TYPE_COLLECTED], true);
    }

    public function label(): string
    {
        return match ($this->type) {
            self::TYPE_SIGNATURE => 'Signed for',
            self::TYPE_HANDED_OVER => 'Handed to a person',
            self::TYPE_LEFT_AT_LOCATION => 'Left at location',
            self::TYPE_COLLECTED => 'Collected by recipient',
            default => 'Delivery method not stated',
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'label' => $this->label(),
            'detail' => $this->detail,
            'location' => $this->location,
            'attributable' => $this->isAttributable(),
            'source' => 'carrier_status',
        ];
    }

    /** @param list<string> $needles */
    private static function matches(string $blob, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($blob, $needle)) {
                return true;
            }
        }

        return false;
    }
}
