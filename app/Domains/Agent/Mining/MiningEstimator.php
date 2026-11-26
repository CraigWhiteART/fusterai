<?php

namespace App\Domains\Agent\Mining;

use App\Ai\EmbeddingService;
use App\Domains\Agent\Mining\Models\MiningRun;
use App\Domains\Agent\Mining\Stages\Extractor;
use App\Domains\Agent\Pipeline\UsageMeter;
use App\Enums\ThreadType;
use App\Services\AiSettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Responses\Data\Usage;

/**
 * Dry-run estimate: what a run with these options would select, how many
 * model calls it would make per stage, the tokens and the cost. Nothing is
 * stored and no model is called.
 *
 * Token assumptions (4 characters ~ 1 token):
 *   extract     system prompt (measured) + each conversation's message text
 *               (each turn capped at extract.turn_max_chars, the whole at
 *               extract.transcript_max_tokens) + 150 for framing; output =
 *               extract.expected_output_tokens. Conversations with an
 *               extraction of the current extractor version are counted as
 *               reused (0 cost). Canned-reply copies are not predicted, so
 *               the estimate is an upper bound there.
 *   cluster     one call when embeddings are off: 3,000 in, expected output out.
 *   synthesise  situations = min(max_situations, ceil(selected / 50));
 *               per call 3,500 system + sample x 450 + statements x 40 in,
 *               synthesis.expected_output_tokens out.
 *   voice       one call: 2,500 + replies x (measured average reply / 4 + 20) in.
 * Prices: config('agent.prices') for the model each role resolves to.
 */
class MiningEstimator
{
    public function __construct(
        private readonly MiningSelector $selector,
        private readonly MiningPrompts $prompts,
        private readonly PlaybookCatalogue $catalogue,
        private readonly AiSettingsService $ai,
        private readonly EmbeddingService $embeddings,
    ) {}

    /**
     * @param  array{since: string, until: string, max_conversations: int, budget_usd: float, sample_strategy: string}  $options
     * @return array<string, mixed>
     */
    public function estimate(int $workspaceId, array $options): array
    {
        $since = Carbon::parse($options['since']);
        $until = Carbon::parse($options['until']);
        $selected = $this->selector->select($workspaceId, $since, $until, $options['max_conversations'], $options['sample_strategy']);
        $ids = array_column($selected, 'id');
        $count = count($ids);

        $byMonth = [];
        foreach ($selected as $c) {
            $byMonth[$c['bucket']] = ($byMonth[$c['bucket']] ?? 0) + 1;
        }
        ksort($byMonth);

        $turnMax = max(200, (int) config('agent_mining.extract.turn_max_chars', 3000));
        $transcriptMax = max(2000, (int) config('agent_mining.extract.transcript_max_tokens', 5000) * 4);
        $replyMax = max(200, (int) config('agent_mining.voice.reply_max_chars', 900));

        $reused = 0;
        $chars = [];
        $replyChars = 0;
        $replyCount = 0;
        $message = ThreadType::Message->value;
        foreach (array_chunk($ids, 1000) as $chunk) {
            $reused += (int) DB::table('agent_mining_extractions')
                ->whereIn('conversation_id', $chunk)
                ->where('extractor_version', Extractor::version())
                ->distinct()
                ->count('conversation_id');

            $rows = DB::table('threads')
                ->whereIn('conversation_id', $chunk)
                ->where('type', $message)
                ->groupBy('conversation_id')
                ->selectRaw('conversation_id, sum(least(length(coalesce(body_plain, \'\')), ?)) as chars', [$turnMax])
                ->get();
            foreach ($rows as $row) {
                $chars[(int) $row->conversation_id] = min($transcriptMax, (int) $row->chars);
            }

            $staff = DB::table('threads')
                ->whereIn('conversation_id', $chunk)
                ->where('type', $message)
                ->whereNull('customer_id')
                ->selectRaw('count(*) as n, coalesce(sum(least(length(coalesce(body_plain, \'\')), ?)), 0) as chars', [$replyMax])
                ->first();
            $replyCount += (int) ($staff->n ?? 0);
            $replyChars += (int) ($staff->chars ?? 0);
        }

        $toExtract = max(0, $count - $reused);
        $avgChars = $chars === [] ? 0 : array_sum($chars) / count($chars);
        $systemTokens = MiningLlm::estimateTokens($this->prompts->extractSystem($this->catalogue->forWorkspace($workspaceId)));
        $extractIn = (int) round($toExtract * ($systemTokens + $avgChars / 4 + 150));
        $extractOut = $toExtract * (int) config('agent_mining.extract.expected_output_tokens', 1200);

        $embeddingsOn = $this->embeddings->isConfigured($workspaceId);
        $clusterCalls = $embeddingsOn || $count === 0 ? 0 : 1;

        $maxSituations = (int) config('agent_mining.cluster.max_situations', 30);
        $situations = $count === 0 ? 0 : min($maxSituations, (int) ceil($count / 50));
        $sample = $situations === 0 ? 0 : min((int) config('agent_mining.synthesis.sample_size', 40), (int) ceil($count / $situations));
        $statements = min((int) config('agent_mining.synthesis.statement_limit', 80), $sample * 2);
        $synthIn = $situations * (3500 + $sample * 450 + $statements * 40);
        $synthOut = $situations * (int) config('agent_mining.synthesis.expected_output_tokens', 6000);

        $voiceReplies = min((int) config('agent_mining.voice.sample_size', 150), $count);
        $avgReply = $replyCount === 0 ? 0 : $replyChars / $replyCount;
        $voiceCalls = $voiceReplies > 0 ? 1 : 0;
        $voiceIn = $voiceCalls * (int) round(2500 + $voiceReplies * ($avgReply / 4 + 20));
        $voiceOut = $voiceCalls * (int) config('agent_mining.voice.expected_output_tokens', 6000);

        $stages = [
            MiningRun::STAGE_EXTRACT => $this->stage($workspaceId, 'history_extraction', $toExtract, $extractIn, $extractOut),
            MiningRun::STAGE_CLUSTER => $this->stage($workspaceId, 'history_extraction', $clusterCalls, $clusterCalls * 3000, $clusterCalls * (int) config('agent_mining.cluster.expected_output_tokens', 4000)),
            MiningRun::STAGE_SYNTHESISE => $this->stage($workspaceId, 'manager', $situations, $synthIn, $synthOut),
            MiningRun::STAGE_VOICE => $this->stage($workspaceId, 'manager', $voiceCalls, $voiceIn, $voiceOut),
        ];

        $micro = array_sum(array_column($stages, 'cost_micro_usd'));

        return [
            'options' => $options,
            'eligible' => $this->selector->countEligible($workspaceId, $since, $until),
            'selected' => $count,
            'selected_by_month' => $byMonth,
            'already_extracted' => $reused,
            'to_extract' => $toExtract,
            'situations_estimated' => $situations,
            'embeddings' => $embeddingsOn,
            'stages' => $stages,
            'total' => [
                'calls' => array_sum(array_column($stages, 'calls')),
                'tokens_in' => array_sum(array_column($stages, 'tokens_in')),
                'tokens_out' => array_sum(array_column($stages, 'tokens_out')),
                'cost_micro_usd' => $micro,
                'cost_usd' => MiningRun::usd($micro),
            ],
            'budget_usd' => $options['budget_usd'],
            'within_budget' => $micro <= (int) round($options['budget_usd'] * 1_000_000),
            'assumptions' => [
                'chars_per_token' => 4,
                'extract_system_tokens' => $systemTokens,
                'avg_transcript_tokens' => (int) round($avgChars / 4),
                'extract_output_tokens' => (int) config('agent_mining.extract.expected_output_tokens', 1200),
                'synthesis_sample' => $sample,
                'synthesis_output_tokens' => (int) config('agent_mining.synthesis.expected_output_tokens', 6000),
                'voice_replies' => $voiceReplies,
                'avg_reply_tokens' => (int) round($avgReply / 4),
                'macro_copies_predicted' => 0,
            ],
        ];
    }

    /** @return array{calls: int, tokens_in: int, tokens_out: int, model: string|null, cost_micro_usd: int, cost_usd: float} */
    private function stage(int $workspaceId, string $task, int $calls, int $in, int $out): array
    {
        $model = $this->ai->resolveCredentials($workspaceId, $task)['model'] ?? null;
        $micro = $calls === 0 ? 0 : UsageMeter::microUsd($model, new Usage($in, $out));

        return ['calls' => $calls, 'tokens_in' => $in, 'tokens_out' => $out, 'model' => $model, 'cost_micro_usd' => $micro, 'cost_usd' => MiningRun::usd($micro)];
    }
}
