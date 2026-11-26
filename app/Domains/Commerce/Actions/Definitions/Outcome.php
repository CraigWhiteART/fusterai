<?php

namespace App\Domains\Commerce\Actions\Definitions;

use App\Domains\Commerce\Actions\Gateway\GatewayResult;

/**
 * What an execute() or verify() call concluded.
 *
 *   succeeded  Shopify accepted it (execute) / the effect is visible (verify)
 *   failed     Shopify refused it with userErrors, or nothing was sent / the effect is absent
 *   unknown    it may or may not have happened: reconcile, never re-send
 *   pending    (verify only) accepted but not visible yet (orderCancel runs as a job)
 */
final class Outcome
{
    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const UNKNOWN = 'unknown';

    public const PENDING = 'pending';

    /**
     * @param  list<array<string, mixed>>  $requests  every request sent (redacted)
     * @param  list<array<string, mixed>>  $responses  every response received (redacted)
     * @param  array<string, mixed>  $data  ids etc. for verify / undo
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $error = null,
        public readonly array $requests = [],
        public readonly array $responses = [],
        public readonly array $data = [],
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromResult(GatewayResult $result, string $field, string $errorsField = 'userErrors', array $data = []): self
    {
        $requests = [$result->request];
        $responses = [$result->response];

        if ($result->outcome === GatewayResult::DRY_RUN) {
            return new self(self::FAILED, 'Dry run: nothing was sent to Shopify.', $requests, $responses);
        }
        if ($result->outcome === GatewayResult::UNKNOWN) {
            return new self(self::UNKNOWN, $result->errorText(), $requests, $responses);
        }
        if ($result->outcome === GatewayResult::REJECTED) {
            return new self(self::FAILED, $result->errorText(), $requests, $responses);
        }

        $payload = $result->data[$field] ?? null;
        if (! is_array($payload)) {
            // 200 without the payload: Shopify may have run it.
            return new self(self::UNKNOWN, "Shopify returned no {$field} payload.", $requests, $responses);
        }

        $errors = array_values(array_filter((array) ($payload[$errorsField] ?? []), 'is_array'));
        if ($errors !== []) {
            $text = implode('; ', array_map(fn ($e) => trim(implode('.', (array) ($e['field'] ?? [])).' '.($e['message'] ?? '')), $errors));

            return new self(self::FAILED, 'Shopify refused: '.$text, $requests, $responses, ['user_errors' => $errors]);
        }

        return new self(self::SUCCEEDED, null, $requests, $responses, [...$data, 'payload' => $payload]);
    }

    public function then(self $next): self
    {
        return new self($next->status, $next->error, [...$this->requests, ...$next->requests], [...$this->responses, ...$next->responses], [...$this->data, ...$next->data]);
    }
}
