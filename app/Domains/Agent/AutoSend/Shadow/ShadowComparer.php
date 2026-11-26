<?php

namespace App\Domains\Agent\AutoSend\Shadow;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\FrozenCase;
use App\Domains\Agent\Models\ShadowComparison;
use App\Domains\Agent\Pipeline\InstructionSet;
use App\Domains\Agent\Pipeline\TranscriptBuilder;
use App\Domains\Agent\Simulation\CaseScorer;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Enums\ThreadType;
use App\Support\PlainText;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shadow mode made useful: when a human answers a message the agent
 * answered in shadow, the simulation judge (CaseScorer, `simulation_judge`
 * role) compares the agent's reply with the human's. Writes only an
 * agent_shadow_comparisons row: no AiSuggestion, no thread, no broadcast,
 * no conversation change, no mail. Sampled (agent.auto_send.shadow.sample_rate)
 * and capped per day (shadow.daily_cap) to bound judge cost.
 */
class ShadowComparer
{
    public function __construct(
        private readonly CaseScorer $scorer,
        private readonly TranscriptBuilder $transcripts,
    ) {}

    /** The shadow run a staff reply answers, if it still needs a comparison. */
    public function pendingRunFor(Thread $reply): ?AgentRun
    {
        if ($reply->type !== ThreadType::Message || $reply->customer_id !== null
            || in_array($reply->origin, [Thread::ORIGIN_AI, Thread::ORIGIN_IMPORT], true)) {
            return null;
        }

        return AgentRun::where('conversation_id', $reply->conversation_id)
            ->where('purpose', AgentRun::PURPOSE_SHADOW)
            ->where('status', AgentRun::STATUS_COMPLETED)
            ->whereNotNull('customer_thread_id')
            ->where('customer_thread_id', '<', $reply->id)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('agent_shadow_comparisons')->whereColumn('agent_shadow_comparisons.run_id', 'agent_runs.id'))
            ->latest('id')
            ->first();
    }

    public function compare(AgentRun $run, Thread $human): ?ShadowComparison
    {
        if ($run->purpose !== AgentRun::PURPOSE_SHADOW || ShadowComparison::where('run_id', $run->id)->exists()) {
            return null;
        }

        $auto = (array) ($run->auto_send ?? []);
        $base = [
            'workspace_id' => $run->workspace_id,
            'run_id' => $run->id,
            'conversation_id' => $run->conversation_id,
            'playbook_id' => $run->playbook_id,
            'human_thread_id' => $human->id,
            'would_auto_send' => (bool) ($auto['would_auto_send'] ?? false),
            'eligible_if_auto' => (bool) ($auto['eligible_if_auto'] ?? false),
            'ai_decision' => $run->decision,
        ];

        $skip = $this->skipReason($run);
        if ($skip !== null) {
            return $this->store([...$base, 'status' => ShadowComparison::STATUS_SKIPPED, 'skip_reason' => $skip]);
        }

        try {
            $case = $this->transientCase($run, $human);
            $result = $this->scorer->score($case, null, $run, InstructionSet::live((int) $run->workspace_id));
        } catch (Throwable $e) {
            Log::warning('Shadow comparison failed', ['run_id' => $run->id, 'error' => $e->getMessage()]);

            return $this->store([...$base, 'status' => ShadowComparison::STATUS_FAILED, 'scores' => ['error' => class_basename($e).': '.mb_substr($e->getMessage(), 0, 300)]]);
        }

        $judge = (array) ($result['judge'] ?? []);
        if (($judge['status'] ?? null) !== 'ok') {
            return $this->store([...$base, 'status' => ShadowComparison::STATUS_FAILED, 'scores' => $result, 'cost_micro_usd' => (int) ($result['cost_micro_usd'] ?? 0)]);
        }

        $values = array_values(array_filter([
            $judge['outcome_match'] ?? null, $judge['policy_consistency'] ?? null, $judge['factual_grounding'] ?? null, $judge['tone_match'] ?? null,
        ], fn ($v) => $v !== null));

        return $this->store([
            ...$base,
            'status' => ShadowComparison::STATUS_OK,
            'agreed' => $result['passed'] === true,
            'score' => $values !== [] ? round(array_sum($values) / count($values), 2) : null,
            'scores' => $result,
            'cost_micro_usd' => (int) ($result['cost_micro_usd'] ?? 0),
        ]);
    }

    private function skipReason(AgentRun $run): ?string
    {
        $rate = max(0.0, min(1.0, (float) config('agent.auto_send.shadow.sample_rate', 1.0)));
        // Stable per run, so a retry samples the same way.
        if ((crc32('shadow-sample:'.$run->id) % 10_000) / 10_000 >= $rate) {
            return 'sampled_out';
        }

        $judged = ShadowComparison::where('workspace_id', $run->workspace_id)
            ->whereIn('status', [ShadowComparison::STATUS_OK, ShadowComparison::STATUS_FAILED])
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
        if ($judged >= (int) config('agent.auto_send.shadow.daily_cap', 50)) {
            return 'daily_cap';
        }

        return null;
    }

    /** A FrozenCase that is never saved: the conversation cut at the message the run answered, plus the human reply. */
    private function transientCase(AgentRun $run, Thread $human): FrozenCase
    {
        $conversation = Conversation::findOrFail($run->conversation_id);
        $turns = [];
        foreach ($this->transcripts->fromConversation($conversation)->turns as $turn) {
            $turns[] = $turn->toArray();
            if ($turn->threadId === $run->customer_thread_id) {
                break;
            }
        }

        $case = new FrozenCase([
            'workspace_id' => $run->workspace_id,
            'conversation_id' => $conversation->id,
            'cut_thread_id' => $run->customer_thread_id,
            'human_thread_id' => $human->id,
            'subject' => (string) $conversation->subject,
            'transcript' => $turns,
            // The shadow run used live Shopify / carrier evidence.
            'evidence_quality' => FrozenCase::QUALITY_RECORDED,
            'human_reply' => PlainText::from($human->body_plain ?: $human->body),
            'source' => 'manual',
        ]);
        $case->workspace_id = (int) $run->workspace_id;

        return $case;
    }

    /** @param  array<string, mixed>  $attributes */
    private function store(array $attributes): ?ShadowComparison
    {
        try {
            return ShadowComparison::create($attributes);
        } catch (UniqueConstraintViolationException) {
            return ShadowComparison::where('run_id', $attributes['run_id'])->first();
        }
    }
}
