<?php

namespace App\Domains\Agent\Pipeline;

use Laravel\Ai\Responses\Data\Usage;

/**
 * Accumulates token usage across every model call in a run and prices it
 * from config('agent.prices') (USD per million tokens) into micro-dollars.
 */
final class UsageMeter
{
    public int $in = 0;

    public int $out = 0;

    public int $cacheRead = 0;

    public int $cacheWrite = 0;

    public int $reasoning = 0;

    public int $microUsd = 0;

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function record(string $step, ?string $model, ?Usage $usage): void
    {
        $usage ??= new Usage;

        $this->in += $usage->promptTokens;
        $this->out += $usage->completionTokens;
        $this->cacheRead += $usage->cacheReadInputTokens;
        $this->cacheWrite += $usage->cacheWriteInputTokens;
        $this->reasoning += $usage->reasoningTokens;

        $cost = self::microUsd($model, $usage);
        $this->microUsd += $cost;

        $this->calls[] = [
            'step' => $step,
            'model' => $model,
            'tokens_in' => $usage->promptTokens,
            'tokens_out' => $usage->completionTokens,
            'cache_read' => $usage->cacheReadInputTokens,
            'cache_write' => $usage->cacheWriteInputTokens,
            'reasoning' => $usage->reasoningTokens,
            'cost_micro_usd' => $cost,
        ];
    }

    public static function microUsd(?string $model, Usage $usage): int
    {
        $prices = (array) config('agent.prices', []);
        // OpenRouter ids ("anthropic/claude-haiku-5.5", "...:batch") price
        // like the vendor's own id ("claude-haiku-5-5").
        if ($model !== null && ! isset($prices[$model])) {
            $base = (string) preg_replace('/:.*$/', '', substr($model, (int) strrpos('/'.$model, '/')));
            $model = isset($prices[$base]) ? $base : str_replace('.', '-', $base);
        }
        $price = $prices[(string) $model] ?? null;

        if ($price === null && $model !== null) {
            // Dated or suffixed ids (claude-haiku-5-5-20260101) price like their base id.
            foreach ($prices as $id => $candidate) {
                if ($id !== 'default' && str_starts_with($model, (string) $id)) {
                    $price = $candidate;
                    break;
                }
            }
        }
        $price ??= $prices['default'] ?? ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0];

        // Output already includes reasoning tokens on Anthropic; never add them twice.
        $usd = $usage->promptTokens * (float) ($price['input'] ?? 0)
            + $usage->completionTokens * (float) ($price['output'] ?? 0)
            + $usage->cacheReadInputTokens * (float) ($price['cache_read'] ?? 0)
            + $usage->cacheWriteInputTokens * (float) ($price['cache_write'] ?? 0);

        // per-million price * tokens = micro-dollars exactly.
        return (int) round($usd);
    }
}
