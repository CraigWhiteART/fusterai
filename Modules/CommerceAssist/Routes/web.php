<?php

use Illuminate\Support\Facades\Route;
use Modules\CommerceAssist\Http\Controllers\ApprovedResponseController;
use Modules\CommerceAssist\Http\Controllers\ConversationContextController;
use Modules\CommerceAssist\Http\Controllers\ExampleController;
use Modules\CommerceAssist\Http\Controllers\IntentController;
use Modules\CommerceAssist\Http\Controllers\ReplayController;
use Modules\CommerceAssist\Http\Controllers\SettingsController;

Route::middleware(['auth', 'module.active:CommerceAssist'])->group(function () {
    Route::get('/settings/commerce-assist', [SettingsController::class, 'index'])->name('settings.commerce-assist');
    Route::post('/settings/commerce-assist', [SettingsController::class, 'update'])->name('settings.commerce-assist.update');

    Route::get('/settings/commerce-assist/intents', [IntentController::class, 'index'])->name('settings.commerce-assist.intents');
    Route::put('/settings/commerce-assist/intents/{intent}', [IntentController::class, 'update'])->name('settings.commerce-assist.intents.update');

    Route::get('/settings/commerce-assist/examples', [ApprovedResponseController::class, 'index'])->name('settings.commerce-assist.examples');
    Route::post('/settings/commerce-assist/examples', [ApprovedResponseController::class, 'store'])->name('settings.commerce-assist.examples.store');
    Route::delete('/settings/commerce-assist/examples/{example}', [ApprovedResponseController::class, 'destroy'])->name('settings.commerce-assist.examples.destroy');

    Route::get('/settings/commerce-assist/replay', [ReplayController::class, 'index'])->name('settings.commerce-assist.replay');
    Route::post('/settings/commerce-assist/replay/{conversation}', [ReplayController::class, 'run'])->name('settings.commerce-assist.replay.run');
    Route::patch('/settings/commerce-assist/replay/{run}/mark', [ReplayController::class, 'mark'])->name('settings.commerce-assist.replay.mark');

    Route::get('/commerce-assist/conversations/{conversation}', [ConversationContextController::class, 'show'])->name('commerce-assist.context');
    Route::post('/commerce-assist/conversations/{conversation}/shopify-refresh', [ConversationContextController::class, 'refreshShopify'])->name('commerce-assist.shopify-refresh');
    Route::post('/commerce-assist/threads/{thread}/example', [ExampleController::class, 'fromThread'])->name('commerce-assist.thread-example');
    Route::post('/commerce-assist/generations/{generation}/example', [ExampleController::class, 'fromGeneration'])->name('commerce-assist.generation-example');
});
