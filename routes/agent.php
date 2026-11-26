<?php

use App\Domains\Agent\Http\Controllers\AgentSettingsController;
use App\Domains\Agent\Http\Controllers\AutoSendController;
use App\Domains\Agent\Http\Controllers\FeedbackController;
use App\Domains\Agent\Http\Controllers\FrozenCaseController;
use App\Domains\Agent\Http\Controllers\KnowledgeController;
use App\Domains\Agent\Http\Controllers\ManagerChatController;
use App\Domains\Agent\Http\Controllers\OverviewController;
use App\Domains\Agent\Http\Controllers\PlaybookController;
use App\Domains\Agent\Http\Controllers\ProposalController;
use App\Domains\Agent\Http\Controllers\QaController;
use App\Domains\Agent\Http\Controllers\ReportController;
use App\Domains\Agent\Http\Controllers\RunController;
use App\Domains\Agent\Http\Controllers\SimulationController;
use App\Domains\Agent\Http\Controllers\TestCaseController;
use App\Domains\Agent\Http\Controllers\VoiceController;
use App\Domains\Agent\Mining\Http\MiningController;
use App\Domains\Commerce\Http\Controllers\ConnectionsController;
use App\Domains\Commerce\Http\Controllers\ConversationCommerceController;
use Illuminate\Support\Facades\Route;

/*
 * Agent routes (loaded by AgentServiceProvider inside the `web` group).
 * Instruction screens are admin-only (`manage-settings`, checked in each
 * controller like the other settings pages); run viewing / regenerate need
 * only access to the conversation. See docs/agent-api.md.
 */
Route::middleware('auth')->prefix('agent')->name('agent.')->group(function () {
    Route::get('/settings', [AgentSettingsController::class, 'show'])->name('settings');
    Route::patch('/settings', [AgentSettingsController::class, 'update'])->name('settings.update');

    Route::get('/voice', [VoiceController::class, 'index'])->name('voice');
    Route::post('/voice', [VoiceController::class, 'store'])->name('voice.store');
    Route::post('/voice/versions/{version}/restore', [VoiceController::class, 'restore'])->name('voice.restore');

    Route::get('/playbooks', [PlaybookController::class, 'index'])->name('playbooks.index');
    Route::get('/playbooks/create', [PlaybookController::class, 'create'])->name('playbooks.create');
    Route::get('/playbooks/options', [PlaybookController::class, 'list'])->name('playbooks.options');
    Route::post('/playbooks', [PlaybookController::class, 'store'])->name('playbooks.store');
    Route::get('/playbooks/{playbook}', [PlaybookController::class, 'show'])->name('playbooks.show');
    Route::patch('/playbooks/{playbook}', [PlaybookController::class, 'updateSettings'])->name('playbooks.update');
    Route::post('/playbooks/{playbook}/versions', [PlaybookController::class, 'storeVersion'])->name('playbooks.versions.store');
    Route::post('/playbooks/{playbook}/versions/{version}/restore', [PlaybookController::class, 'restore'])->name('playbooks.versions.restore');

    Route::get('/knowledge', [KnowledgeController::class, 'index'])->name('knowledge.index');
    Route::get('/knowledge/create', [KnowledgeController::class, 'create'])->name('knowledge.create');
    Route::post('/knowledge', [KnowledgeController::class, 'store'])->name('knowledge.store');
    Route::get('/knowledge/{item}/edit', [KnowledgeController::class, 'edit'])->name('knowledge.edit');
    Route::patch('/knowledge/{item}', [KnowledgeController::class, 'update'])->name('knowledge.update');

    Route::get('/conversations/{conversation}/run', [RunController::class, 'show'])->name('runs.show');
    Route::post('/conversations/{conversation}/regenerate', [RunController::class, 'regenerate'])
        ->middleware('throttle:10,1')
        ->name('runs.regenerate');
});

// ── Overview, commerce connections and the conversation order panel ─────────
// (added by the agent-UI work; controllers in app/Domains/Agent/Http and
// app/Domains/Commerce/Http. Admin checks happen in the controllers.)
Route::middleware('auth')->group(function () {
    Route::get('/agent', [OverviewController::class, 'show'])->name('agent.overview');

    Route::get('/agent/connections', [ConnectionsController::class, 'show'])->name('agent.connections');
    Route::patch('/agent/connections', [ConnectionsController::class, 'update'])->name('agent.connections.update');
    Route::post('/agent/connections/test-shopify', [ConnectionsController::class, 'testShopify'])
        ->middleware('throttle:10,1')
        ->name('agent.connections.test-shopify');
    Route::post('/agent/connections/test-tracking', [ConnectionsController::class, 'testTracking'])
        ->middleware('throttle:10,1')
        ->name('agent.connections.test-tracking');

    Route::get('/commerce/conversations/{conversation}', [ConversationCommerceController::class, 'show'])->name('commerce.conversation');
    Route::post('/commerce/conversations/{conversation}/refresh-shopify', [ConversationCommerceController::class, 'refreshShopify'])
        ->middleware('throttle:20,1')
        ->name('commerce.conversation.refresh-shopify');
    Route::post('/commerce/conversations/{conversation}/refresh-tracking', [ConversationCommerceController::class, 'refreshTracking'])
        ->middleware('throttle:20,1')
        ->name('commerce.conversation.refresh-tracking');
});
// ── end: overview / connections / order panel ─────────────────────────────

// ── Screen pages (Inertia) ──────────────────────────────────────────────────
// Pages with no JSON twin. Pages that share a URL with a JSON index
// (/agent/proposals, /agent/simulations, /agent/mining, /agent/actions/policies,
// /agent/actions/audit) use the `page:` middleware (RenderPageUnlessJson): an
// Inertia/browser navigation gets the page, an axios/JSON call gets the API.
Route::middleware(['auth', 'can:manage-settings'])->prefix('agent')->name('agent.pages.')->group(function () {
    Route::inertia('/manager', 'Agent/Manager/Index')->name('manager');
    Route::inertia('/qa', 'Agent/Qa/Index')->name('qa');
    Route::inertia('/reports', 'Agent/Reports/Index')->name('reports');
    Route::inertia('/shadow', 'Agent/Shadow/Index')->name('shadow');
});
// ── end: screen pages ──────────────────────────────────────────────────────

// ── History mining (app/Domains/Agent/Mining, docs/agent-mining.md) ─────────
// JSON only; admin check (manage-settings) in the controller.
Route::middleware('auth')->prefix('agent/mining')->name('agent.mining.')->group(function () {
    Route::get('/', [MiningController::class, 'index'])->middleware('page:Agent/Mining/Index,manage-settings')->name('index');
    Route::post('/estimate', [MiningController::class, 'estimate'])
        ->middleware('throttle:20,1')
        ->name('estimate');
    Route::post('/', [MiningController::class, 'store'])->name('store');
    Route::get('/{run}', [MiningController::class, 'show'])->whereNumber('run')->name('show');
    Route::post('/{run}/{action}', [MiningController::class, 'action'])
        ->whereNumber('run')
        ->whereIn('action', ['pause', 'resume', 'cancel'])
        ->name('action');
});
// ── end: history mining ────────────────────────────────────────────────────

// ── Feedback loop, proposals, test cases, simulation (docs/agent-proposals.md) ──
// JSON only. Feedback + proposal show/preview: anyone who can view the
// conversation; everything else: manage-settings (checked in the controllers).
Route::middleware('auth')->prefix('agent')->name('agent.')->group(function () {
    $feedback = FeedbackController::class;
    $proposals = ProposalController::class;
    $tests = TestCaseController::class;
    $sims = SimulationController::class;
    $cases = FrozenCaseController::class;

    Route::post('/conversations/{conversation}/feedback', [$feedback, 'store'])->middleware('throttle:20,1')->name('feedback.store');
    Route::get('/conversations/{conversation}/feedback', [$feedback, 'forConversation'])->name('feedback.conversation');
    Route::get('/feedback/{feedback}', [$feedback, 'show'])->whereNumber('feedback')->name('feedback.show');
    Route::post('/feedback/{feedback}/scope', [$feedback, 'scope'])->whereNumber('feedback')->middleware('throttle:20,1')->name('feedback.scope');
    Route::post('/feedback/{feedback}/answer', [$feedback, 'answer'])->whereNumber('feedback')->middleware('throttle:20,1')->name('feedback.answer');
    Route::post('/suggestions/{suggestion}/dismiss', [$feedback, 'dismissSuggestion'])->whereNumber('suggestion')->name('suggestions.dismiss');

    Route::get('/proposals', [$proposals, 'index'])->middleware('page:Agent/Proposals/Index,manage-settings')->name('proposals.index');
    Route::get('/proposals/{proposal}', [$proposals, 'show'])->whereNumber('proposal')->name('proposals.show');
    Route::post('/proposals/{proposal}/check', [$proposals, 'check'])->whereNumber('proposal')->middleware('throttle:20,1')->name('proposals.check');
    Route::post('/proposals/{proposal}/resolve-conflict', [$proposals, 'resolveConflict'])->whereNumber('proposal')->name('proposals.resolve');
    Route::post('/proposals/{proposal}/preview', [$proposals, 'preview'])->whereNumber('proposal')->middleware('throttle:10,1')->name('proposals.preview');
    Route::post('/proposals/{proposal}/gate', [$proposals, 'gate'])->whereNumber('proposal')->middleware('throttle:10,1')->name('proposals.gate');
    Route::post('/proposals/{proposal}/exclude', [$proposals, 'exclude'])->whereNumber('proposal')->middleware('throttle:30,1')->name('proposals.exclude');
    Route::post('/proposals/{proposal}/exclusions/restore', [$proposals, 'restoreExclusions'])->whereNumber('proposal')->middleware('throttle:30,1')->name('proposals.exclusions.restore');
    Route::post('/proposals/{proposal}/approve', [$proposals, 'approve'])->whereNumber('proposal')->name('proposals.approve');
    Route::post('/proposals/{proposal}/reject', [$proposals, 'reject'])->whereNumber('proposal')->name('proposals.reject');
    Route::post('/proposals/{proposal}/rebase', [$proposals, 'rebase'])->whereNumber('proposal')->name('proposals.rebase');
    Route::post('/proposals/{proposal}/revert', [$proposals, 'revert'])->whereNumber('proposal')->name('proposals.revert');

    Route::get('/test-cases', [$tests, 'index'])->name('test-cases.index');
    Route::post('/test-cases', [$tests, 'store'])->name('test-cases.store');
    Route::get('/test-cases/{testCase}', [$tests, 'show'])->whereNumber('testCase')->name('test-cases.show');
    Route::patch('/test-cases/{testCase}', [$tests, 'update'])->whereNumber('testCase')->name('test-cases.update');
    Route::delete('/test-cases/{testCase}', [$tests, 'destroy'])->whereNumber('testCase')->name('test-cases.destroy');
    Route::post('/playbooks/{playbook}/tests/run', [$tests, 'runPlaybook'])->whereNumber('playbook')->middleware('throttle:10,1')->name('playbooks.tests.run');

    Route::post('/simulations/estimate', [$sims, 'estimate'])->name('simulations.estimate');
    Route::get('/simulations', [$sims, 'index'])->middleware('page:Agent/Simulations/Index,manage-settings')->name('simulations.index');
    Route::post('/simulations', [$sims, 'store'])->middleware('throttle:10,1')->name('simulations.store');
    Route::get('/simulations/{simulation}', [$sims, 'show'])->whereNumber('simulation')->name('simulations.show');
    Route::get('/simulations/{simulation}/results', [$sims, 'results'])->whereNumber('simulation')->name('simulations.results');
    Route::post('/simulations/{simulation}/pause', [$sims, 'pause'])->whereNumber('simulation')->name('simulations.pause');
    Route::post('/simulations/{simulation}/resume', [$sims, 'resume'])->whereNumber('simulation')->name('simulations.resume');
    Route::post('/simulations/{simulation}/cancel', [$sims, 'cancel'])->whereNumber('simulation')->name('simulations.cancel');
    Route::patch('/simulations/{simulation}/results/{result}', [$sims, 'overrideResult'])->whereNumber(['simulation', 'result'])->name('simulations.results.override');

    Route::get('/frozen-cases', [$cases, 'index'])->name('frozen-cases.index');
    Route::get('/frozen-cases/{frozenCase}', [$cases, 'show'])->whereNumber('frozenCase')->name('frozen-cases.show');
    Route::post('/frozen-cases/build', [$cases, 'build'])->middleware('throttle:5,1')->name('frozen-cases.build');
    Route::post('/frozen-cases/prepare', [$cases, 'prepare'])->middleware('throttle:5,1')->name('frozen-cases.prepare');
});
// ── end: feedback loop ─────────────────────────────────────────────────────

// ── Auto-send and rollout (docs/agent-autosend.md) ──────────────────────────
// JSON only. Cancel: ConversationPolicy::update; "this was wrong":
// ConversationPolicy::view; everything else: manage-settings (in the controller).
Route::middleware('auth')->prefix('agent')->name('agent.autosend.')->group(function () {
    Route::post('/auto-sends/{thread}/cancel', [AutoSendController::class, 'cancel'])->whereNumber('thread')->middleware('throttle:30,1')->name('cancel');
    Route::post('/auto-sends/{thread}/wrong', [AutoSendController::class, 'wrong'])->whereNumber('thread')->middleware('throttle:20,1')->name('wrong');
    Route::post('/autosend/pause', [AutoSendController::class, 'pause'])->name('pause');
    Route::post('/autosend/resume', [AutoSendController::class, 'resume'])->name('resume');
    Route::get('/autosend/shadow-report', [AutoSendController::class, 'shadowReport'])->name('shadow-report');
    Route::get('/playbooks/{playbook}/autosend', [AutoSendController::class, 'playbookStatus'])->whereNumber('playbook')->name('playbook');
});
// ── end: auto-send ─────────────────────────────────────────────────────────

// ── Manager chat, QA reviews, AI performance report (docs/agent-manager.md, docs/agent-qa.md) ──
// JSON (+ one CSV). manage-settings, checked in the controllers.
Route::middleware('auth')->prefix('agent')->name('agent.')->group(function () {
    Route::get('/manager/chats', [ManagerChatController::class, 'index'])->name('manager.chats.index');
    Route::post('/manager/chats', [ManagerChatController::class, 'store'])->middleware('throttle:20,1')->name('manager.chats.store');
    Route::get('/manager/chats/{chat}', [ManagerChatController::class, 'show'])->whereNumber('chat')->name('manager.chats.show');
    Route::patch('/manager/chats/{chat}', [ManagerChatController::class, 'update'])->whereNumber('chat')->name('manager.chats.update');
    Route::delete('/manager/chats/{chat}', [ManagerChatController::class, 'destroy'])->whereNumber('chat')->name('manager.chats.destroy');
    Route::post('/manager/chats/{chat}/messages', [ManagerChatController::class, 'message'])->whereNumber('chat')->middleware('throttle:10,1')->name('manager.chats.messages');

    Route::get('/qa/reviews', [QaController::class, 'index'])->name('qa.reviews.index');
    Route::get('/qa/reviews/{review}', [QaController::class, 'show'])->whereNumber('review')->name('qa.reviews.show');
    Route::post('/qa/reviews/{review}/verdict', [QaController::class, 'verdict'])->whereNumber('review')->name('qa.reviews.verdict');
    Route::post('/qa/conversations/{conversation}/review', [QaController::class, 'reviewConversation'])->whereNumber('conversation')->middleware('throttle:20,1')->name('qa.conversations.review');
    Route::get('/qa/capability-requests', [QaController::class, 'capabilityRequests'])->name('qa.capability-requests');
    Route::get('/qa/gaps', [QaController::class, 'gaps'])->name('qa.gaps');
    Route::get('/qa/aggregates', [QaController::class, 'aggregates'])->name('qa.aggregates');

    Route::get('/reports/performance', [ReportController::class, 'performance'])->name('reports.performance');
    Route::get('/reports/performance.csv', [ReportController::class, 'csv'])->name('reports.performance.csv');
});
// ── end: manager chat / QA / report ────────────────────────────────────────
