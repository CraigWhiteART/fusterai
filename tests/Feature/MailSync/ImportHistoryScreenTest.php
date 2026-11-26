<?php

use App\Domains\Conversation\Models\Conversation;
use App\Domains\Mailbox\Models\Mailbox;
use App\Domains\Mailbox\Sync\Backfill\BackfillService;
use App\Domains\Mailbox\Sync\Backfill\Jobs\RunMailBackfillJob;
use App\Domains\Mailbox\Sync\Backfill\MailBackfillRun;
use App\Domains\Mailbox\Sync\Backfill\MailBackfillThread;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

function ihMailbox(Workspace $workspace): Mailbox
{
    return Mailbox::factory()->create([
        'workspace_id' => $workspace->id,
        'imap_config' => ['host' => 'imap.gmail.com', 'port' => 993, 'encryption' => 'ssl', 'username' => 'support@souldial.test', 'password' => 'secret'],
    ]);
}

beforeEach(function () {
    Queue::fake();
    $this->workspace = Workspace::factory()->create();
    $this->mailbox = ihMailbox($this->workspace);
});

test('guests are sent to the login page', function () {
    $this->get('/agent/import')->assertRedirect('/login');
});

test('only admins can open the import screen or start, pause, resume, cancel and undo imports', function () {
    $run = app(BackfillService::class)->start($this->mailbox, dispatch: false);

    foreach ([agentUser($this->workspace), managerUser($this->workspace)] as $user) {
        $this->actingAs($user)->get('/agent/import')->assertForbidden();
        $this->actingAs($user)->post('/agent/import', ['mailbox_id' => $this->mailbox->id, 'months' => 6])->assertForbidden();
        foreach (['pause', 'resume', 'cancel', 'undo'] as $action) {
            $this->actingAs($user)->post("/agent/import/{$run->id}/{$action}")->assertForbidden();
        }
    }

    expect($run->fresh()->status)->toBe(MailBackfillRun::STATUS_QUEUED);
});

test('an admin sees the mailboxes, defaults, runs, sample conversations and dropped threads', function () {
    $admin = adminUser($this->workspace);
    $run = app(BackfillService::class)->start($this->mailbox, months: 6, dispatch: false);
    $run->forceFill(['status' => 'completed', 'stage' => 'done', 'counters' => ['threads_kept' => 1, 'conversations_created' => 1, 'dropped_reasons' => ['bulk' => 2]]])->save();
    $conversation = Conversation::factory()->create([
        'workspace_id' => $this->workspace->id, 'mailbox_id' => $this->mailbox->id, 'status' => 'closed', 'imported' => true, 'subject' => 'Where is my order?',
    ]);
    $conversation->forceFill(['backfill_run_id' => $run->id])->save();
    MailBackfillThread::create([
        'run_id' => $run->id, 'thread_key' => 'g:1', 'decision' => 'dropped', 'reason' => 'bulk', 'message_ids' => [1],
        'message_count' => 1, 'subject' => 'Our summer sale', 'last_at' => now(),
    ]);
    // Another workspace's run is never shown.
    $other = Workspace::factory()->create();
    app(BackfillService::class)->start(ihMailbox($other), dispatch: false);

    $this->actingAs($admin)->get('/agent/import')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Agent/Import')
            ->has('mailboxes', 1)
            ->where('mailboxes.0.id', $this->mailbox->id)
            ->where('defaults.months', 18)
            ->has('runs', 1)
            ->where('selected.id', $run->id)
            ->where('selected.status', 'completed')
            ->where('selected.counters.conversations_created', 1)
            ->where('selected.dropped_reasons.bulk', 2)
            ->where('selected.can.undo', true)
            ->where('selected.samples.0.id', $conversation->id)
            ->where('selected.samples.0.url', route('conversations.show', $conversation->id))
            ->where('selected.dropped_samples.0.subject', 'Our summer sale')
        );
});

test('an admin starts a dry run and the job chain is queued on ai-bulk', function () {
    $admin = adminUser($this->workspace);

    $this->actingAs($admin)
        ->post('/agent/import', ['mailbox_id' => $this->mailbox->id, 'months' => 6, 'dry_run' => true, 'include_unanswered' => true])
        ->assertRedirect();

    $run = MailBackfillRun::sole();
    expect($run->isDryRun())->toBeTrue()
        ->and($run->months)->toBe(6)
        ->and($run->option('include_unanswered'))->toBeTrue()
        ->and($run->user_id)->toBe($admin->id);
    Queue::assertPushedOn('ai-bulk', RunMailBackfillJob::class);

    // A second one for the same mailbox is refused while this one is active.
    $this->actingAs($admin)
        ->post('/agent/import', ['mailbox_id' => $this->mailbox->id, 'months' => 6])
        ->assertSessionHas('error');
    expect(MailBackfillRun::count())->toBe(1);
});

test('the mailbox must belong to the admin\'s workspace', function () {
    $foreign = ihMailbox(Workspace::factory()->create());

    $this->actingAs(adminUser($this->workspace))
        ->post('/agent/import', ['mailbox_id' => $foreign->id, 'months' => 6])
        ->assertSessionHasErrors('mailbox_id');

    expect(MailBackfillRun::count())->toBe(0);
});

test('pause, resume and cancel from the screen; another workspace cannot touch the run', function () {
    $admin = adminUser($this->workspace);
    $run = app(BackfillService::class)->start($this->mailbox, dispatch: false);

    $this->actingAs(adminUser(Workspace::factory()->create()))->post("/agent/import/{$run->id}/pause")->assertNotFound();
    expect($run->fresh()->status)->toBe(MailBackfillRun::STATUS_QUEUED);

    $this->actingAs($admin)->post("/agent/import/{$run->id}/pause")->assertRedirect()->assertSessionHas('success');
    expect($run->fresh()->status)->toBe(MailBackfillRun::STATUS_PAUSED);

    $this->actingAs($admin)->post("/agent/import/{$run->id}/resume")->assertSessionHas('success');
    expect($run->fresh()->status)->toBe(MailBackfillRun::STATUS_RUNNING);
    Queue::assertPushed(RunMailBackfillJob::class, fn ($job) => $job->token === $run->fresh()->job_token);

    // Undo is only for finished imports.
    $this->actingAs($admin)->post("/agent/import/{$run->id}/undo")->assertSessionHas('error');

    $this->actingAs($admin)->post("/agent/import/{$run->id}/cancel")->assertSessionHas('success');
    expect($run->fresh()->status)->toBe(MailBackfillRun::STATUS_CANCELLED);

    $this->actingAs($admin)->post("/agent/import/{$run->id}/undo")->assertSessionHas('success');
    expect($run->fresh()->status)->toBe(MailBackfillRun::STATUS_UNDOING);
});
