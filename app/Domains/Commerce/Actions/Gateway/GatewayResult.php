<?php

namespace App\Domains\Commerce\Actions\Gateway;

/**
 * One Admin GraphQL round-trip, classified for the executor:
 *
 *  - ok           HTTP 200 with `data` (userErrors are the caller's to read)
 *  - rejected     Shopify definitely did not execute it (4xx, throttled,
 *                 GraphQL validation errors with no data)
 *  - unknown      timeout, connection error, 5xx, or errors alongside data:
 *                 it may or may not have run. Never re-send; reconcile.
 *  - dry_run      the dry-run gateway: nothing was sent
 */
final class GatewayResult
{
    public const OK = 'ok';

    public const REJECTED = 'rejected';

    public const UNKNOWN = 'unknown';

    public const DRY_RUN = 'dry_run';

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $errors
     * @param  array<string, mixed>  $request  query, variables, endpoint (no credentials)
     * @param  array<string, mixed>  $response  status + body (redacted by the caller)
     */
    public function __construct(
        public readonly string $outcome,
        public readonly array $data = [],
        public readonly array $errors = [],
        public readonly ?int $status = null,
        public readonly ?string $message = null,
        public readonly array $request = [],
        public readonly array $response = [],
    ) {}

    public function ok(): bool
    {
        return $this->outcome === self::OK;
    }

    public function errorText(): string
    {
        if ($this->message !== null) {
            return $this->message;
        }

        return implode('; ', array_filter(array_map(fn ($e) => (string) ($e['message'] ?? ''), $this->errors))) ?: 'Shopify request failed.';
    }
}
