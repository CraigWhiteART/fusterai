<?php

use App\Domains\Commerce\Actions\Http\Controllers\ActionExecutionController;
use App\Domains\Commerce\Actions\Http\Controllers\ActionPolicyController;
use Illuminate\Support\Facades\Route;

/*
 * Shopify actions (loaded by App\Domains\Commerce\Actions\ActionsServiceProvider
 * inside the `web` group: session auth + CSRF). JSON only. Authorization is
 * checked in the controllers; see docs/agent-actions.md.
 */
Route::middleware('auth')->prefix('agent/actions')->name('agent.actions.')->group(function () {
    // Approvals queue page (Inertia). Its data comes from GET /executions,
    // which applies the approver-role check.
    Route::inertia('/', 'Agent/Actions/Index')->name('page');
    Route::get('/catalogue', [ActionPolicyController::class, 'catalogue'])->name('catalogue');

    Route::get('/settings', [ActionPolicyController::class, 'settings'])->name('settings');
    Route::patch('/settings', [ActionPolicyController::class, 'updateSettings'])->name('settings.update');

    Route::get('/policies', [ActionPolicyController::class, 'policies'])->middleware('page:Agent/Actions/Policies,manage-settings')->name('policies');
    Route::put('/policies/{action}', [ActionPolicyController::class, 'updatePolicy'])->where('action', '[a-z_]+')->name('policies.update');
    Route::delete('/policies/{policy}', [ActionPolicyController::class, 'destroyPolicy'])->whereNumber('policy')->name('policies.destroy');

    Route::get('/executions', [ActionExecutionController::class, 'index'])->name('executions.index');
    Route::get('/executions/{execution}', [ActionExecutionController::class, 'show'])->whereNumber('execution')->name('executions.show');
    Route::post('/executions/{execution}/approve', [ActionExecutionController::class, 'approve'])->whereNumber('execution')->middleware('throttle:30,1')->name('executions.approve');
    Route::post('/executions/{execution}/reject', [ActionExecutionController::class, 'reject'])->whereNumber('execution')->middleware('throttle:30,1')->name('executions.reject');
    Route::post('/executions/{execution}/refresh', [ActionExecutionController::class, 'refresh'])->whereNumber('execution')->middleware('throttle:20,1')->name('executions.refresh');

    Route::get('/audit', [ActionExecutionController::class, 'audit'])->middleware('page:Agent/Actions/Audit,manage-settings')->name('audit');

    Route::get('/conversations/{conversation}', [ActionExecutionController::class, 'forConversation'])->whereNumber('conversation')->name('conversation');
    Route::get('/runs/{run}/gate', [ActionExecutionController::class, 'runGate'])->whereNumber('run')->name('runs.gate');
});
