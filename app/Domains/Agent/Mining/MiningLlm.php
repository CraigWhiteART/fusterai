<?php

namespace App\Domains\Agent\Mining;

use App\Domains\Agent\Mining\Agents\ExtractConversationAgent;
use App\Domains\Agent\Mining\Agents\MergeSituationsAgent;
use App\Domains\Agent\Mining\Agents\SynthesiseSituationAgent;
use App\Domains\Agent\Mining\Agents\VoiceProfileAgent;
use App\Domains\Agent\Mining\Models\MiningCache;
use App\Domains\Agent\Mining\Models\MiningRun;
use App\Domains\Agent\Pipeline\UsageMeter;
use App\Services\AiSettingsService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Responses\Data\Usage;

/**
 * Every model call a mining run makes goes through here:
 *   1. optional result cache (kind + input hash): a hit costs nothing;
 *   2. hard budget check: estimated cost of this call (prompt length / 4 +
 *      the stage's expected output tokens, or the stage's average real call
 *      cost so far if higher) on top of what the run has spent must stay
 *      under the cap, otherwise BudgetExhausted (the runner pauses). A call
 *      that costs more than its estimate can still overshoot the cap once;
 *   3. the call on the role's model with the workspace's credentials;
 *   4. tokens and cost added to the run (per stage) and saved at once, so a
 *      failure later in the slice never loses spend.
 */
class MiningLlm
{
    public function __construct(
        private readonly AiSettingsService $ai,
    ) {}

    /**
     * @param  ExtractConversationAgent|MergeSituationsAgent|SynthesiseSituationAgent|VoiceProfileAgent  $agent
     * @return array{data: array<string, mixed>, cached: bool, model: string|null, tokens_in: int, tokens_out: int, cost_micro_usd: int}
     */
    public function call(
        MiningRun $run,
        string $stage,
        string $task,
        Agent $agent,
        string $user,
        int $expectedOutputTokens,
        ?string $cacheKind = null,
        ?string $cacheHash = null,
    ): array {
        if ($cacheKind !== null && $cacheHash !== null) {
            $hit = MiningCache::query()
                ->where('workspace_id', $run->workspace_id)
                ->where('kind', $cacheKind)
                ->where('input_hash', $cacheHash)
                ->first();
            if ($hit !== null) {
                $this->addUsage($run, $stage, cacheHit: true);
                $run->save();

                return ['data' => $hit->output, 'cached' => true, 'model' => $hit->model, 'tokens_in' => 0, 'tokens_out' => 0, 'cost_micro_usd' => 0];
            }
        }

        $model = $this->ai->resolveCredentials($run->workspace_id, $task)['model'] ?? null;
        $estimate = UsageMeter::microUsd($model, new Usage(
            self::estimateTokens($agent->instructions().$user),
            $expectedOutputTokens,
        ));
        // Once the stage has made calls, their real average is the better guess.
        $seen = (array) (($run->usage ?? [])[$stage] ?? []);
        if ((int) ($seen['calls'] ?? 0) > 0) {
            $estimate = max($estimate, intdiv((int) $seen['cost_micro_usd'], (int) $seen['calls']));
        }
        if ($run->cost_micro_usd + $estimate > $run->budget_micro_usd) {
            throw new BudgetExhausted($run->cost_micro_usd, $run->budget_micro_usd, $estimate, $stage);
        }

        $response = $this->ai->withWorkspaceCredentials(
            $run->workspace_id,
            fn ($lab, $model, $options = []) => $agent->withProviderOptions($options)->prompt($user, provider: $lab, model: $model),
            task: $task,
        );

        $usage = $response->usage;
        $used = $response->meta->model ?? $model;
        $cost = UsageMeter::microUsd($used, $usage);
        $data = method_exists($response, 'toArray') ? (array) $response->toArray() : [];

        $this->addUsage($run, $stage, tokensIn: $usage->promptTokens + $usage->cacheReadInputTokens + $usage->cacheWriteInputTokens, tokensOut: $usage->completionTokens, cost: $cost);
        $run->save();

        if ($cacheKind !== null && $cacheHash !== null) {
            try {
                DB::transaction(fn () => MiningCache::query()->create([
                    'workspace_id' => $run->workspace_id,
                    'kind' => $cacheKind,
                    'input_hash' => $cacheHash,
                    'output' => $data,
                    'model' => $used,
                    'tokens_in' => $usage->promptTokens,
                    'tokens_out' => $usage->completionTokens,
                    'cost_micro_usd' => $cost,
                ]));
            } catch (UniqueConstraintViolationException) {
                // Another run stored the same result meanwhile.
            }
        }

        return [
            'data' => $data,
            'cached' => false,
            'model' => $used,
            'tokens_in' => $usage->promptTokens,
            'tokens_out' => $usage->completionTokens,
            'cost_micro_usd' => $cost,
        ];
    }

    /** Rough token count for estimates (4 characters ~ 1 token). */
    public static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    /** Stable hash of a call's input data (never of the rendered prompt, which carries a nonce). */
    public static function inputHash(string $version, mixed $input): string
    {
        return hash('sha256', $version."\n".json_encode($input, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    private function addUsage(MiningRun $run, string $stage, int $tokensIn = 0, int $tokensOut = 0, int $cost = 0, bool $cacheHit = false): void
    {
        $usage = $run->usage ?? [];
        $row = $usage[$stage] ?? ['calls' => 0, 'cache_hits' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'cost_micro_usd' => 0];
        if ($cacheHit) {
            $row['cache_hits']++;
        } else {
            $row['calls']++;
        }
        $row['tokens_in'] += $tokensIn;
        $row['tokens_out'] += $tokensOut;
        $row['cost_micro_usd'] += $cost;
        $usage[$stage] = $row;

        $run->usage = $usage;
        $run->tokens_in += $tokensIn;
        $run->tokens_out += $tokensOut;
        $run->cost_micro_usd += $cost;
    }
}
