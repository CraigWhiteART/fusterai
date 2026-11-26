<?php

use App\Domains\Conversation\Models\Conversation;
use App\Domains\Customer\Models\Customer;
use App\Domains\Mailbox\Models\Mailbox;
use App\Models\Workspace;
use App\Services\ReportsService;
use Illuminate\Support\Facades\Mail;
use Modules\SatisfactionSurvey\Jobs\SendSurveyJob;

/**
 * Thousands of closed, imported history conversations must not distort the
 * day-to-day numbers or trigger customer-facing mail.
 */
beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    $this->mailbox = Mailbox::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->customer = Customer::factory()->create(['workspace_id' => $this->workspace->id, 'email' => 'emma@example.net']);

    $make = fn (bool $imported, string $status, string $created, string $lastReply) => tap(Conversation::factory()->create([
        'workspace_id' => $this->workspace->id,
        'mailbox_id' => $this->mailbox->id,
        'customer_id' => $this->customer->id,
        'status' => $status,
        'imported' => $imported,
        'last_reply_at' => $lastReply,
    ]), fn (Conversation $c) => $c->forceFill(['created_at' => $created])->saveQuietly());

    $this->live = $make(false, 'closed', now()->subDays(3)->toDateTimeString(), now()->subDays(2)->toDateTimeString());
    // Imported history inside the report window, with a long "resolution".
    $this->imported = $make(true, 'closed', now()->subDays(5)->toDateTimeString(), now()->subDays(1)->toDateTimeString());
});

test('reports leave imported history out of volume, breakdowns and resolution time', function () {
    $stats = app(ReportsService::class)->stats($this->workspace->id, 30);

    expect($stats['total'])->toBe(1)
        ->and($stats['closed'])->toBe(1)
        ->and($stats['trend']->sum('count'))->toBe(1)
        ->and($stats['by_mailbox']->sum('count'))->toBe(1)
        ->and($stats['avg_resolution_hours'])->toEqual(24.0);
});

test('the dashboard trend and recent list leave imported history out', function () {
    $this->actingAs(agentUser($this->workspace))
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('recent', 1)
            ->where('recent.0.id', $this->live->id)
            ->where('stats.trend.0.count', 1)
        );
});

test('imported conversations never show as unread in the list', function () {
    $this->actingAs(adminUser($this->workspace))
        ->get('/conversations?status=closed')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('conversations.data', 2)
            ->where('conversations.data.0.id', $this->imported->id)
            ->where('conversations.data.0.is_unread', false)
            ->where('conversations.data.1.is_unread', true)
        );
});

test('no satisfaction survey is ever sent for imported history', function () {
    Mail::fake();

    (new SendSurveyJob($this->imported))->handle();

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});
