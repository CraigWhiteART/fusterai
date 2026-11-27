<?php

namespace App\Domains\Agent\Http\Controllers;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\PlaybookVersion;
use App\Domains\Agent\Pipeline\HandoverReasons;
use App\Domains\AI\Jobs\GenerateReplySuggestionJob;
use App\Domains\Conversation\Models\Conversation;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * JSON for the conversation draft panel. Any user who can view the
 * conversation may read its run; regenerate needs update access.
 * Payload shapes are documented in docs/agent-api.md.
 */
class RunController extends Controller
{
    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $purpose = $request->validate(['purpose' => ['nullable', Rule::in([AgentRun::PURPOSE_LIVE, AgentRun::PURPOSE_SHADOW])]])['purpose'] ?? AgentRun::PURPOSE_LIVE;

        $run = AgentRun::with(['playbook', 'playbookVersion', 'feedback'])
            ->where('conversation_id', $conversation->id)
            ->where('purpose', $purpose)
            ->latest('id')
            ->first();

        return response()->json([
            'agent_mode' => AgentSetting::forWorkspace((int) $conversation->workspace_id)->mode,
            'run' => $run !== null ? self::present($run) : null,
        ]);
    }

    public function regenerate(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);

        $settings = AgentSetting::forWorkspace((int) $conversation->workspace_id);
        if (! $settings->pipelineOwnsDrafts()) {
            return response()->json([
                'message' => "The agent is in {$settings->mode} mode; switch it to draft to regenerate.",
                'agent_mode' => $settings->mode,
            ], 409);
        }
        if ($settings->kill_switch) {
            return response()->json(['message' => 'The agent kill switch is on.', 'agent_mode' => $settings->mode], 409);
        }

        GenerateReplySuggestionJob::dispatch($conversation, regenerate: true);

        return response()->json(['queued' => true], 202);
    }

    /** @return array<string, mixed> */
    public static function present(AgentRun $run): array
    {
        $evidence = (array) ($run->evidence ?? []);
        $facts = [];
        foreach ((array) ($evidence['facts'] ?? []) as $fact) {
            $facts[(string) $fact['id']] = $fact;
        }
        $knowledge = [];
        foreach ((array) ($evidence['knowledge'] ?? []) as $item) {
            $knowledge[(string) $item['id']] = $item;
        }

        $resolve = function (string $id) use ($facts, $knowledge): array {
            if (isset($facts[$id])) {
                $f = $facts[$id];

                return ['id' => $id, 'type' => 'evidence', 'source' => $f['source'], 'label' => $f['label'], 'value' => $f['value']];
            }
            if (isset($knowledge[$id])) {
                $k = $knowledge[$id];

                return ['id' => $id, 'type' => 'knowledge', 'kind' => $k['kind'] ?? null, 'title' => $k['title'] ?? null, 'body' => $k['body'] ?? '',
                    'knowledge_item_id' => str_starts_with($id, 'K') && ctype_digit(substr($id, 1)) ? (int) substr($id, 1) : null];
            }

            return ['id' => $id, 'type' => 'unknown'];
        };

        $version = $run->playbookVersion;
        $rulesById = [];
        foreach (($version instanceof PlaybookVersion ? ($version->rules ?? []) : []) as $rule) {
            $rulesById[(string) $rule['id']] = $rule;
        }

        $meta = (array) ($evidence['meta'] ?? []);
        unset($meta['shopify_payload']);

        return [
            'id' => $run->id,
            'purpose' => $run->purpose,
            'status' => $run->status,
            'decision' => $run->decision,
            // Phase 8: {blockers[], would_auto_send, eligible_if_auto, status, thread_id?, scheduled_for?, sent_at?, cancel_reason?, events[]}
            'auto_send' => $run->auto_send,
            'handover_reason' => $run->handover_reason,
            'handover_label' => $run->handover_reason !== null ? HandoverReasons::label($run->handover_reason) : null,
            'draft' => $run->draft,
            'internal_note' => $run->internal_note,
            'ai_suggestion_id' => $run->ai_suggestion_id,
            'customer_thread_id' => $run->customer_thread_id,
            'playbook' => $run->playbook !== null ? [
                'id' => $run->playbook->id,
                'slug' => $run->playbook->slug,
                'name' => $run->playbook->name,
                'version_id' => $run->playbook_version_id,
                'version' => $version?->version,
            ] : null,
            'triage' => $run->triage,
            'rules_used' => array_map(fn ($id) => [
                'id' => $id,
                'if' => $rulesById[$id]['if'] ?? null,
                'then' => $rulesById[$id]['then'] ?? null,
            ], array_values((array) ($run->rules_used ?? []))),
            'claims' => array_map(fn ($claim) => [
                'text' => (string) ($claim['text'] ?? ''),
                'sources' => array_map(fn ($id) => $resolve((string) $id), array_values((array) ($claim['sources'] ?? []))),
            ], array_values((array) ($run->claims ?? []))),
            'proposed_actions' => array_values((array) ($run->proposed_actions ?? [])),
            'validator' => $run->validator,
            'evidence' => [
                'facts' => array_values($facts),
                'notes' => array_values((array) ($evidence['notes'] ?? [])),
                'guidance' => array_values((array) ($evidence['guidance'] ?? [])),
                'meta' => $meta,
            ],
            'knowledge' => array_values($knowledge),
            // The customer's other conversations the writer was shown: [{id, status, waiting_on_us}].
            'related_conversations' => array_values((array) ($run->trace['related_conversations'] ?? [])),
            'tokens' => [
                'in' => $run->tokens_in,
                'out' => $run->tokens_out,
                'cache_read' => $run->tokens_cache_read,
                'cache_write' => $run->tokens_cache_write,
                'reasoning' => $run->tokens_reasoning,
            ],
            'cost_micro_usd' => $run->cost_micro_usd,
            'cost_usd' => round($run->cost_micro_usd / 1_000_000, 6),
            'latency_ms' => $run->latency_ms,
            'models' => $run->config['models'] ?? [],
            'config_hash' => $run->config_hash,
            'error' => $run->error,
            'feedback' => $run->feedback->map(fn ($event) => [
                'id' => $event->id,
                'kind' => $event->kind,
                'percent_changed' => $event->percent_changed,
                'thread_id' => $event->thread_id,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->values()->all(),
            'created_at' => $run->created_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
        ];
    }
}
