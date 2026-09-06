<?php

namespace Modules\CommerceAssist\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CommerceAssist\Models\HistoryLearning;
use Modules\CommerceAssist\Services\HistoryMiner;
use Modules\CommerceAssist\Services\IntentCatalog;
use RuntimeException;

class HistoryLearnController extends Controller
{
    public function index(Request $request, HistoryMiner $miner): Response
    {
        $this->authorize('manage-settings');

        $workspaceId = (int) $request->user()->workspace_id;
        IntentCatalog::ensureForWorkspace($workspaceId);

        $kind = $request->string('kind')->toString();
        $kind = in_array($kind, HistoryLearning::REVIEW_KINDS, true) ? $kind : '';

        $learnings = HistoryLearning::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'pending')
            ->whereIn('kind', HistoryLearning::REVIEW_KINDS)
            ->when($kind !== '', fn ($query) => $query->where('kind', $kind))
            ->orderByRaw("case kind when 'knowledge' then 1 when 'live_fact' then 2 else 3 end")
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn (HistoryLearning $learning) => $learning->toUiArray());

        $scan = $miner->activeScan($workspaceId) ?? $miner->latestScan($workspaceId);

        return Inertia::render('Settings/CommerceAssistLearn', [
            'stats' => $miner->stats($workspaceId),
            'scan' => $scan?->toUiArray(),
            'learnings' => $learnings,
            'filter' => $kind,
            'intents' => IntentCatalog::slugs(),
        ]);
    }

    public function scan(Request $request, HistoryMiner $miner): RedirectResponse
    {
        $this->authorize('manage-settings');

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:5', 'max:80'],
        ]);

        try {
            $scan = $miner->start(
                (int) $request->user()->workspace_id,
                $request->user(),
                (int) ($validated['limit'] ?? HistoryMiner::DEFAULT_LIMIT),
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = $scan->total_count === 0
            ? 'No sent emails left to learn from.'
            : 'Scanning '.$scan->total_count.' sent emails. Review proposals as they appear.';

        return back()->with('success', $message);
    }

    public function accept(Request $request, HistoryLearning $learning, HistoryMiner $miner): RedirectResponse
    {
        $this->authorize('manage-settings');
        abort_unless($learning->workspace_id === $request->user()->workspace_id, 404);

        $validated = $request->validate([
            'kind' => ['nullable', 'string', 'in:approved_reply,knowledge,live_fact'],
            'title' => ['nullable', 'string', 'max:180'],
            'body' => ['nullable', 'string', 'max:8000'],
            'customer_message' => ['nullable', 'string', 'max:20000'],
            'final_reply' => ['nullable', 'string', 'max:20000'],
            'intent' => ['nullable', 'string', 'max:80'],
            'subtype' => ['nullable', 'string', 'max:80'],
            'product_keywords' => ['nullable'],
            'expires_at' => ['nullable', 'date'],
        ]);

        try {
            $miner->accept($learning, $request->user(), $validated);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Saved. Next item is ready.');
    }

    public function reject(Request $request, HistoryLearning $learning, HistoryMiner $miner): RedirectResponse
    {
        $this->authorize('manage-settings');
        abort_unless($learning->workspace_id === $request->user()->workspace_id, 404);

        try {
            $miner->reject($learning, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back();
    }

    public function acceptReplies(Request $request, HistoryMiner $miner): RedirectResponse
    {
        $this->authorize('manage-settings');

        $accepted = $miner->acceptRemainingReplies((int) $request->user()->workspace_id, $request->user());

        return back()->with('success', $accepted->count().' approved replies saved.');
    }
}
