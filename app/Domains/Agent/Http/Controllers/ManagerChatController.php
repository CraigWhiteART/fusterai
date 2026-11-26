<?php

namespace App\Domains\Agent\Http\Controllers;

use App\Domains\Agent\Manager\ManagerChatService;
use App\Domains\Agent\Manager\Models\ManagerChat;
use App\Domains\Agent\Manager\Models\ManagerMessage;
use App\Domains\Agent\Manager\ScopeGuard;
use App\Domains\Agent\Models\Playbook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Ask the manager" chat API (JSON, manage-settings). A chat belongs to the
 * admin who created it; other users get 404. docs/agent-manager.md.
 */
class ManagerChatController extends AgentController
{
    public function __construct(private readonly ManagerChatService $service) {}

    public function index(Request $request): JsonResponse
    {
        $ws = $this->workspaceFor($request);
        $chats = ManagerChat::where('workspace_id', $ws)->where('user_id', $request->user()->id)
            ->orderByDesc('updated_at')->paginate(min(100, max(1, (int) $request->integer('per_page', 30))));

        return response()->json([
            'data' => $chats->getCollection()->map(fn (ManagerChat $c) => $this->chat($c))->values(),
            'meta' => ['current_page' => $chats->currentPage(), 'last_page' => $chats->lastPage(), 'per_page' => $chats->perPage(), 'total' => $chats->total()],
            'limits' => $this->limits($ws),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->workspaceFor($request);
        $data = $request->validate($this->restrictionRules() + ['title' => ['nullable', 'string', 'max:200']]);
        $chat = $this->service->createChat($request->user(), $data['title'] ?? null, $this->restrictionInput($request, $data));

        return response()->json(['chat' => $this->chat($chat), 'messages' => []], 201);
    }

    public function show(Request $request, int $chat): JsonResponse
    {
        $ws = $this->workspaceFor($request);
        $model = $this->owned($request, $ws, $chat);
        $this->service->openTurn($model);

        return response()->json([
            'chat' => $this->chat($model->fresh() ?? $model),
            'messages' => ManagerMessage::where('chat_id', $model->id)->orderBy('id')->get()->map(fn (ManagerMessage $m) => self::presentMessage($m))->values(),
            'limits' => $this->limits($ws),
        ]);
    }

    public function update(Request $request, int $chat): JsonResponse
    {
        $ws = $this->workspaceFor($request);
        $model = $this->owned($request, $ws, $chat);
        $data = $request->validate($this->restrictionRules() + ['title' => ['nullable', 'string', 'max:200']]);
        if (array_key_exists('title', $data)) {
            $model->title = $data['title'];
        }
        if (($restrictions = $this->restrictionInput($request, $data)) !== null) {
            $model->restrictions = $this->service->userRestrictions($restrictions, $model->restrictions);
        }
        $model->save();

        return response()->json(['chat' => $this->chat($model)]);
    }

    public function destroy(Request $request, int $chat): JsonResponse
    {
        $ws = $this->workspaceFor($request);
        $this->owned($request, $ws, $chat)->delete();

        return response()->json(['deleted' => true]);
    }

    public function message(Request $request, int $chat): JsonResponse
    {
        $ws = $this->workspaceFor($request);
        $model = $this->owned($request, $ws, $chat);
        $data = $request->validate(['content' => ['required', 'string', 'min:2', 'max:8000']]);

        if (($open = $this->service->openTurn($model)) !== null) {
            return response()->json(['message' => 'The manager is still answering the previous message.', 'reason' => 'turn_running', 'reply' => self::presentMessage($open)], 409);
        }
        if ($this->service->capReached($ws)) {
            return response()->json(['message' => 'The manager\'s daily budget is used up.', 'reason' => 'daily_cap', 'limits' => $this->limits($ws)], 409);
        }

        $result = $this->service->postMessage($model, $request->user(), $data['content']);

        return response()->json([
            'message' => self::presentMessage($result['message']),
            'reply' => self::presentMessage($result['reply']),
            'chat' => $this->chat($model->fresh() ?? $model),
        ], 202);
    }

    private function owned(Request $request, int $ws, int $id): ManagerChat
    {
        $chat = ManagerChat::where('workspace_id', $ws)->where('user_id', $request->user()->id)->find($id);
        abort_if($chat === null, 404);

        return $chat;
    }

    /** @return array<string, list<string>> */
    private function restrictionRules(): array
    {
        return [
            'restrictions' => ['sometimes', 'nullable', 'array'],
            'restrictions.exclude_playbook_ids' => ['sometimes', 'array'],
            'restrictions.exclude_playbook_ids.*' => ['integer'],
            'restrictions.exclude_targets' => ['sometimes', 'array'],
            'restrictions.exclude_targets.*' => ['string', 'in:'.implode(',', ScopeGuard::TARGETS)],
            'restrictions.only_playbook_ids' => ['sometimes', 'nullable', 'array'],
            'restrictions.only_playbook_ids.*' => ['integer'],
            'restrictions.only_targets' => ['sometimes', 'nullable', 'array'],
            'restrictions.only_targets.*' => ['string', 'in:'.implode(',', ScopeGuard::TARGETS)],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function restrictionInput(Request $request, array $data): ?array
    {
        if (! $request->has('restrictions')) {
            return null;
        }
        $r = (array) ($data['restrictions'] ?? []);
        $ws = (int) $request->user()->workspace_id;
        $valid = Playbook::where('workspace_id', $ws)->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach (['exclude_playbook_ids', 'only_playbook_ids'] as $key) {
            if (isset($r[$key]) && is_array($r[$key])) {
                $r[$key] = array_values(array_intersect(array_map('intval', $r[$key]), $valid));
            }
        }

        return $r;
    }

    /** @return array<string, mixed> */
    private function limits(int $ws): array
    {
        return [
            'daily_cost_cap_usd' => round($this->service->capMicro() / 1_000_000, 2),
            'spent_today_usd' => round($this->service->spentTodayMicro($ws) / 1_000_000, 4),
            'cap_reached' => $this->service->capReached($ws),
            'max_steps' => (int) config('agent_manager.max_steps', 10),
            'max_input_tokens' => (int) config('agent_manager.max_input_tokens', 200000),
            'max_proposals_per_turn' => (int) config('agent_manager.max_proposals_per_turn', 3),
        ];
    }

    /** @return array<string, mixed> */
    private function chat(ManagerChat $chat): array
    {
        $r = ScopeGuard::normalize($chat->restrictions);
        $slugs = Playbook::where('workspace_id', $chat->workspace_id)->whereIn('id', [...$r['exclude_playbook_ids'], ...($r['only_playbook_ids'] ?? [])])->pluck('slug', 'id');

        return [
            'id' => $chat->id,
            'title' => $chat->title,
            'restrictions' => $r + [
                'exclude_playbooks' => array_map(fn ($id) => ['id' => $id, 'slug' => $slugs[$id] ?? null], $r['exclude_playbook_ids']),
                'only_playbooks' => $r['only_playbook_ids'] === null ? null : array_map(fn ($id) => ['id' => $id, 'slug' => $slugs[$id] ?? null], $r['only_playbook_ids']),
            ],
            'message_count' => $chat->message_count,
            'running' => ManagerMessage::where('chat_id', $chat->id)->whereIn('status', ManagerMessage::OPEN_STATUSES)->exists(),
            'tokens' => ['in' => $chat->tokens_in, 'out' => $chat->tokens_out],
            'cost_usd' => round($chat->cost_micro_usd / 1_000_000, 4),
            'last_message_at' => $chat->last_message_at?->toIso8601String(),
            'created_at' => $chat->created_at?->toIso8601String(),
            'updated_at' => $chat->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function presentMessage(ManagerMessage $m): array
    {
        return [
            'id' => $m->id,
            'chat_id' => $m->chat_id,
            'role' => $m->role,
            'status' => $m->status,
            'content' => $m->content,
            'reply_to_id' => $m->reply_to_id,
            'tool_calls' => array_map(fn ($c) => array_intersect_key((array) $c, array_flip(['step', 'tool', 'summary', 'ok', 'refused', 'arguments'])), (array) ($m->tool_calls ?? [])),
            'citations' => $m->citations ?? [],
            'proposal_ids' => $m->proposal_ids ?? [],
            'suggestions' => $m->suggestions ?? [],
            'restrictions' => $m->restrictions,
            'limits' => $m->limits,
            'error' => $m->error,
            'model' => $m->model,
            'tokens' => ['in' => $m->tokens_in, 'out' => $m->tokens_out, 'cache_read' => $m->tokens_cache_read, 'cache_write' => $m->tokens_cache_write, 'reasoning' => $m->tokens_reasoning],
            'cost_micro_usd' => $m->cost_micro_usd,
            'cost_usd' => round($m->cost_micro_usd / 1_000_000, 5),
            'latency_ms' => $m->latency_ms,
            'created_at' => $m->created_at?->toIso8601String(),
            'completed_at' => $m->completed_at?->toIso8601String(),
        ];
    }
}
