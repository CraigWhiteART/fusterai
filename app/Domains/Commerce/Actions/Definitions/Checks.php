<?php

namespace App\Domains\Commerce\Actions\Definitions;

/**
 * Precondition results for one action against one order state. Hard checks
 * stop execution whoever approved it; warnings go on the approval card.
 */
final class Checks
{
    /** @var list<array{check: string, ok: bool, detail: string}> */
    public array $items = [];

    /** @var list<array{level: string, text: string}> */
    public array $warnings = [];

    public ?int $amountCents = null;

    public ?string $currency = null;

    /** @var array<string, mixed> action-specific values the executor reuses (computed refund, line items...) */
    public array $computed = [];

    public function check(string $name, bool $ok, string $detail): self
    {
        $this->items[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];

        return $this;
    }

    public function warn(string $text, string $level = 'warning'): self
    {
        $this->warnings[] = ['level' => $level, 'text' => $text];

        return $this;
    }

    public function passed(): bool
    {
        foreach ($this->items as $item) {
            if (! $item['ok']) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    public function failures(): array
    {
        return array_values(array_map(fn ($i) => $i['detail'], array_filter($this->items, fn ($i) => ! $i['ok'])));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed(),
            'checks' => $this->items,
            'warnings' => $this->warnings,
            'amount_cents' => $this->amountCents,
            'currency' => $this->currency,
        ];
    }
}
