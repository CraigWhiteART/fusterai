<?php

namespace App\Domains\Agent\Pipeline;

/**
 * The full conversation in true chronological order, rendered within a
 * token budget: the most recent turns verbatim, older ones summarised to a
 * line, the oldest dropped with a marker.
 */
final class Transcript
{
    /** @var list<TranscriptTurn> */
    public readonly array $turns;

    /** @param  list<TranscriptTurn>  $turns */
    public function __construct(array $turns)
    {
        usort($turns, function (TranscriptTurn $a, TranscriptTurn $b): int {
            return [$a->at->getTimestamp(), $a->threadId ?? 0] <=> [$b->at->getTimestamp(), $b->threadId ?? 0];
        });

        $this->turns = $turns;
    }

    public function latestCustomerTurn(): ?TranscriptTurn
    {
        for ($i = count($this->turns) - 1; $i >= 0; $i--) {
            if ($this->turns[$i]->isCustomer()) {
                return $this->turns[$i];
            }
        }

        return null;
    }

    public function lastTurn(): ?TranscriptTurn
    {
        return $this->turns === [] ? null : $this->turns[count($this->turns) - 1];
    }

    /** All customer text, newest first (order-number extraction, retrieval queries). */
    public function customerText(): string
    {
        $texts = [];
        foreach (array_reverse($this->turns) as $turn) {
            if ($turn->isCustomer() && $turn->text !== '') {
                $texts[] = $turn->text;
            }
        }

        return implode("\n", $texts);
    }

    public function hasCustomerImages(): bool
    {
        foreach ($this->turns as $turn) {
            if ($turn->isCustomer() && $turn->hasImages) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{text: string, included: int, summarised: int, dropped: int}
     */
    public function render(int $maxTokens, int $verbatimTurns, int $turnMaxChars): array
    {
        $budget = max(500, $maxTokens) * 4;
        $count = count($this->turns);
        $lines = [];
        $used = 0;
        $summarised = 0;
        $dropped = 0;

        // Walk newest to oldest so the most recent turns always fit.
        for ($i = $count - 1; $i >= 0; $i--) {
            $turn = $this->turns[$i];
            $recent = ($count - 1 - $i) < $verbatimTurns;
            $body = $turn->text;

            if ($recent) {
                if (mb_strlen($body) > $turnMaxChars) {
                    $body = mb_substr($body, 0, $turnMaxChars).' [message truncated]';
                }
            } else {
                $body = mb_substr(preg_replace('/\s+/u', ' ', $body) ?? $body, 0, 240);
                $body = '[summary] '.$body.(mb_strlen($turn->text) > 240 ? '...' : '');
                $summarised++;
            }

            $line = $this->header($turn)."\n".$body;
            $cost = mb_strlen($line) + 2;

            if ($used + $cost > $budget && $lines !== []) {
                $dropped = $i + 1;
                if (! $recent) {
                    $summarised--;
                }
                break;
            }

            $used += $cost;
            array_unshift($lines, $line);
        }

        if ($dropped > 0) {
            array_unshift($lines, "[{$dropped} earlier message(s) omitted to fit the budget]");
        }

        return [
            'text' => implode("\n\n", $lines),
            'included' => $count - $dropped,
            'summarised' => max(0, $summarised),
            'dropped' => $dropped,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(fn (TranscriptTurn $turn) => $turn->toArray(), $this->turns);
    }

    /** @param  list<array<string, mixed>>  $turns */
    public static function fromArray(array $turns): self
    {
        return new self(array_map(fn (array $turn) => TranscriptTurn::fromArray($turn), array_values($turns)));
    }

    private function header(TranscriptTurn $turn): string
    {
        $who = $turn->isCustomer() ? 'CUSTOMER' : 'STAFF';
        $via = $turn->origin === 'gmail' ? ' (sent from Gmail)' : '';
        $images = $turn->hasImages ? ' [images attached]' : '';

        return "--- {$who}{$via} at {$turn->at->toIso8601String()}{$images}";
    }
}
