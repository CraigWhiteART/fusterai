<?php

namespace Modules\CommerceAssist\Services\Tracking;

/**
 * Normalised shipment status. Every provider maps onto these cases so the
 * prompt, the confidence scorer and the UI never see vendor-specific strings.
 */
enum TrackingStatus: string
{
    case Pending = 'pending';
    case InfoReceived = 'info_received';
    case InTransit = 'in_transit';
    case OutForDelivery = 'out_for_delivery';
    case AvailableForPickup = 'available_for_pickup';
    case Delivered = 'delivered';
    case FailedAttempt = 'failed_attempt';
    case Exception = 'exception';
    case Expired = 'expired';
    case NotFound = 'not_found';
    case Unknown = 'unknown';

    public static function fromProvider(?string $value): self
    {
        $key = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', (string) $value), '_'));

        return match ($key) {
            'pending', 'not_yet_shipped', 'awaiting_collection' => self::Pending,
            'info_received', 'inforeceived', 'information_received', 'label_created' => self::InfoReceived,
            'in_transit', 'intransit', 'transit', 'shipped' => self::InTransit,
            'out_for_delivery', 'outfordelivery' => self::OutForDelivery,
            'available_for_pickup', 'availableforpickup', 'pickup', 'ready_for_pickup' => self::AvailableForPickup,
            'delivered', 'signed', 'completed' => self::Delivered,
            'failed_attempt', 'failedattempt', 'attempt_fail', 'attemptfail', 'delivery_failure', 'deliveryfailure' => self::FailedAttempt,
            'exception', 'alert', 'undelivered', 'returned' => self::Exception,
            'expired', 'stale' => self::Expired,
            'not_found', 'notfound', 'no_information' => self::NotFound,
            default => self::Unknown,
        };
    }

    public function isDelivered(): bool
    {
        return $this === self::Delivered;
    }

    /** Carrier is done with the parcel — no further scans are expected. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Expired], true);
    }

    /** A person should look at this before anything is sent. */
    public function needsAttention(): bool
    {
        return in_array($this, [self::Exception, self::FailedAttempt, self::Expired], true);
    }

    /** The carrier has no usable record, so silence is not evidence of a problem. */
    public function isUnknown(): bool
    {
        return in_array($this, [self::NotFound, self::Unknown], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::InfoReceived => 'Label created',
            self::InTransit => 'In transit',
            self::OutForDelivery => 'Out for delivery',
            self::AvailableForPickup => 'Available for pickup',
            self::Delivered => 'Delivered',
            self::FailedAttempt => 'Failed delivery attempt',
            self::Exception => 'Exception',
            self::Expired => 'Expired',
            self::NotFound => 'Not found',
            self::Unknown => 'Unknown',
        };
    }
}
