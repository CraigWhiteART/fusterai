<?php

use App\Domains\AI\Jobs\CategorizeConversationJob;
use App\Domains\AI\Jobs\FetchUrlAndIndexJob;
use App\Domains\AI\Jobs\GenerateReplySuggestionJob;
use App\Domains\AI\Jobs\IndexKbDocumentJob;
use App\Domains\AI\Jobs\SummarizeConversationJob;
use App\Domains\Automation\Jobs\RunAutomationRulesJob;
use App\Domains\Channel\Jobs\HandleLiveChatMessageJob;
use App\Domains\Channel\Jobs\ProcessWebhookMessageJob;
use App\Domains\Channel\Jobs\ProcessWhatsAppWebhookJob;
use App\Domains\Conversation\Jobs\SendAutoReplyJob;
use App\Domains\Conversation\Jobs\SendReplyJob;
use App\Domains\Mailbox\Sync\Jobs\IngestMailMessageJob;

/*
 * A job that outlives its Redis reservation (retry_after) is handed to a second
 * worker while the first is still running, so it runs twice. Every supervisor
 * must satisfy: job timeout < supervisor timeout < connection retry_after.
 */

dataset('horizon environments', ['production', 'local']);

test('every horizon supervisor times out before its connection retry_after', function (string $env) {
    $supervisors = config("horizon.environments.{$env}");
    expect($supervisors)->toBeArray()->not->toBeEmpty();

    foreach ($supervisors as $name => $supervisor) {
        expect($supervisor)->toHaveKey('timeout', message: "{$env}.{$name} has no explicit timeout");

        $connection = $supervisor['connection'];
        $retryAfter = config("queue.connections.{$connection}.retry_after");

        expect(config("queue.connections.{$connection}.driver"))->toBe('redis')
            ->and($retryAfter)->toBeInt()
            ->and($supervisor['timeout'])->toBeLessThan($retryAfter, "{$env}.{$name}: timeout {$supervisor['timeout']} must be < retry_after {$retryAfter}");
    }
})->with('horizon environments');

test('every job timeout is below the timeout of the supervisor that runs it', function (string $env) {
    $jobs = [
        'ai' => [
            CategorizeConversationJob::class,
            FetchUrlAndIndexJob::class,
            GenerateReplySuggestionJob::class,
            IndexKbDocumentJob::class,
            SummarizeConversationJob::class,
        ],
        'email-outbound' => [SendReplyJob::class, SendAutoReplyJob::class],
        'email-inbound' => [IngestMailMessageJob::class],
        'webhooks' => [ProcessWebhookMessageJob::class, ProcessWhatsAppWebhookJob::class],
        'default' => [RunAutomationRulesJob::class, HandleLiveChatMessageJob::class],
    ];

    $supervisors = collect(config("horizon.environments.{$env}"));

    foreach ($jobs as $queue => $classes) {
        $supervisor = $supervisors->first(fn ($s) => in_array($queue, $s['queue'], true));
        expect($supervisor)->not->toBeNull("{$env}: no supervisor for queue {$queue}");

        foreach ($classes as $class) {
            $timeout = (new ReflectionClass($class))->getDefaultProperties()['timeout'] ?? null;
            if ($timeout === null) {
                continue;
            }

            expect($timeout)->toBeLessThan($supervisor['timeout'], "{$class} ({$timeout}s) on {$queue} must finish before the supervisor timeout ({$supervisor['timeout']}s)");
        }
    }
})->with('horizon environments');

test('ai-bulk runs on its own long-reservation connection with a single try', function (string $env) {
    $bulk = config("horizon.environments.{$env}.ai-bulk");

    expect($bulk)->not->toBeNull()
        ->and($bulk['connection'])->toBe('redis-long')
        ->and($bulk['queue'])->toBe(['ai-bulk'])
        ->and($bulk['tries'])->toBe(1)
        ->and($bulk['timeout'])->toBeGreaterThanOrEqual(900)
        ->and(config('queue.connections.redis-long.retry_after'))->toBeGreaterThan($bulk['timeout'])
        // The short connection keeps a short window so crashed short jobs recover quickly.
        ->and(config('queue.connections.redis.retry_after'))->toBeLessThan(config('queue.connections.redis-long.retry_after'));
})->with('horizon environments');
