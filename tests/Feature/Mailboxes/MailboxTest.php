<?php

use App\Domains\Mailbox\Models\Mailbox;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    $this->admin = adminUser($this->workspace);
    $this->agent = agentUser($this->workspace);
});

// ── index ──────────────────────────────────────────────────────────────────────

test('any agent can view the mailboxes list', function () {
    Mailbox::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->agent)
        ->get('/mailboxes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Mailboxes/Index'));
});

test('mailboxes index requires auth', function () {
    $this->get('/mailboxes')->assertRedirect('/login');
});

// ── create ─────────────────────────────────────────────────────────────────────

test('admin can view the create mailbox page', function () {
    $this->actingAs($this->admin)
        ->get('/mailboxes/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Mailboxes/Create'));
});

test('agent cannot view the create mailbox page', function () {
    $this->actingAs($this->agent)
        ->get('/mailboxes/create')
        ->assertForbidden();
});

// ── store ──────────────────────────────────────────────────────────────────────

test('admin can create a mailbox', function () {
    $this->actingAs($this->admin)
        ->post('/mailboxes', [
            'name' => 'Support',
            'email' => 'support@example.com',
        ])
        ->assertRedirect(route('mailboxes.index'));

    $mailbox = Mailbox::where('name', 'Support')->where('workspace_id', $this->workspace->id)->first();
    expect($mailbox)->not->toBeNull();
    expect($mailbox->webhook_token)->not->toBeNull();
});

test('agent cannot create a mailbox', function () {
    $this->actingAs($this->agent)
        ->post('/mailboxes', [
            'name' => 'Support',
            'email' => 'support@example.com',
        ])
        ->assertForbidden();
});

test('mailbox creation requires name and email', function () {
    $this->actingAs($this->admin)
        ->post('/mailboxes', [])
        ->assertSessionHasErrors(['name', 'email']);
});

// ── edit ───────────────────────────────────────────────────────────────────────

test('admin can view mailbox settings page', function () {
    $mailbox = Mailbox::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->admin)
        ->get("/mailboxes/{$mailbox->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Mailboxes/Settings'));
});

test('agent cannot view mailbox settings page', function () {
    $mailbox = Mailbox::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->agent)
        ->get("/mailboxes/{$mailbox->id}/edit")
        ->assertForbidden();
});

// ── update ─────────────────────────────────────────────────────────────────────

test('admin can update mailbox name and signature', function () {
    $mailbox = Mailbox::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->admin)
        ->patch("/mailboxes/{$mailbox->id}", [
            'name' => 'Updated Name',
            'signature' => 'Best regards',
        ])
        ->assertRedirect();

    expect($mailbox->fresh()->name)->toBe('Updated Name');
    expect($mailbox->fresh()->signature)->toBe('Best regards');
});

test('admin can update mailbox imap and smtp config', function () {
    $mailbox = Mailbox::factory()->create(['workspace_id' => $this->workspace->id]);

    $imap = [
        'host' => 'imap.gmail.com',
        'port' => '993',
        'encryption' => 'ssl',
        'username' => 'support@example.com',
        'password' => 'app-password',
    ];
    $smtp = [
        'host' => 'smtp.gmail.com',
        'port' => '587',
        'encryption' => 'tls',
        'username' => 'support@example.com',
        'password' => 'app-password',
    ];

    $this->actingAs($this->admin)
        ->patch("/mailboxes/{$mailbox->id}", [
            'name' => $mailbox->name,
            'email' => $mailbox->email,
            'imap_config' => $imap,
            'smtp_config' => $smtp,
        ])
        ->assertRedirect();

    $fresh = $mailbox->fresh();
    expect($fresh->imap_config)->toMatchArray($imap);
    expect($fresh->smtp_config)->toMatchArray($smtp);

    $rawImap = DB::table('mailboxes')->where('id', $mailbox->id)->value('imap_config');
    expect($rawImap)->not->toContain('app-password');
    expect($rawImap)->not->toContain('imap.gmail.com');
});

test('agent cannot update a mailbox', function () {
    $mailbox = Mailbox::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->agent)
        ->patch("/mailboxes/{$mailbox->id}", ['name' => 'Hijacked'])
        ->assertForbidden();
});

// ── destroy ────────────────────────────────────────────────────────────────────

test('admin can delete a mailbox', function () {
    $mailbox = Mailbox::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->admin)
        ->delete("/mailboxes/{$mailbox->id}")
        ->assertRedirect(route('mailboxes.index'));

    expect(Mailbox::find($mailbox->id))->toBeNull();
});

test('agent cannot delete a mailbox', function () {
    $mailbox = Mailbox::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->agent)
        ->delete("/mailboxes/{$mailbox->id}")
        ->assertForbidden();
});

// ── cross-workspace security ───────────────────────────────────────────────────

test('cannot edit mailbox from another workspace', function () {
    $other = Workspace::factory()->create();
    $mailbox = Mailbox::factory()->create(['workspace_id' => $other->id]);

    $this->actingAs($this->admin)
        ->patch("/mailboxes/{$mailbox->id}", ['name' => 'Hijacked'])
        ->assertForbidden();
});

test('cannot delete mailbox from another workspace', function () {
    $other = Workspace::factory()->create();
    $mailbox = Mailbox::factory()->create(['workspace_id' => $other->id]);

    $this->actingAs($this->admin)
        ->delete("/mailboxes/{$mailbox->id}")
        ->assertForbidden();
});

// ── credential exposure ────────────────────────────────────────────────────────

function mailboxWithCredentials(int $workspaceId): Mailbox
{
    return Mailbox::factory()->create([
        'workspace_id' => $workspaceId,
        'imap_config' => [
            'host' => 'imap.gmail.com',
            'port' => '993',
            'encryption' => 'ssl',
            'username' => 'support@example.com',
            'password' => 'imap-secret-pw',
        ],
        'smtp_config' => [
            'host' => 'smtp.gmail.com',
            'port' => '587',
            'encryption' => 'tls',
            'username' => 'support@example.com',
            'password' => 'smtp-secret-pw',
        ],
    ]);
}

test('mailbox settings page never sends passwords, only has_password', function () {
    $mailbox = mailboxWithCredentials($this->workspace->id);

    $response = $this->actingAs($this->admin)
        ->get("/mailboxes/{$mailbox->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Mailboxes/Settings')
            ->where('mailbox.imap_config.host', 'imap.gmail.com')
            ->where('mailbox.imap_config.has_password', true)
            ->missing('mailbox.imap_config.password')
            ->where('mailbox.smtp_config.encryption', 'tls')
            ->where('mailbox.smtp_config.has_password', true)
            ->missing('mailbox.smtp_config.password')
            ->missing('mailbox.webhook_token'));

    expect($response->getContent())
        ->not->toContain('imap-secret-pw')
        ->not->toContain('smtp-secret-pw');
});

test('mailboxes index does not expose imap or smtp config', function () {
    mailboxWithCredentials($this->workspace->id);

    $response = $this->actingAs($this->agent)
        ->get('/mailboxes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->missing('mailboxes.0.imap_config')
            ->missing('mailboxes.0.smtp_config')
            ->missing('mailboxes.0.webhook_token'));

    expect($response->getContent())
        ->not->toContain('imap-secret-pw')
        ->not->toContain('smtp-secret-pw');
});

test('serialized mailbox hides configs but server code can still read them', function () {
    $mailbox = mailboxWithCredentials($this->workspace->id)->fresh();

    expect($mailbox->toArray())->not->toHaveKeys(['imap_config', 'smtp_config', 'webhook_token'])
        ->and($mailbox->smtp_config['password'])->toBe('smtp-secret-pw')
        ->and($mailbox->imap_config['password'])->toBe('imap-secret-pw');
});

test('blank password on update keeps the stored password', function () {
    $mailbox = mailboxWithCredentials($this->workspace->id);

    $this->actingAs($this->admin)
        ->patch("/mailboxes/{$mailbox->id}", [
            'imap_config' => [
                'host' => 'imap.example.com',
                'port' => '993',
                'encryption' => 'ssl',
                'username' => 'new@example.com',
                'password' => '',
            ],
            'smtp_config' => [
                'host' => 'smtp.example.com',
                'port' => '465',
                'encryption' => 'ssl',
                'username' => 'new@example.com',
                // password omitted entirely
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $fresh = $mailbox->fresh();
    expect($fresh->imap_config['host'])->toBe('imap.example.com')
        ->and($fresh->imap_config['username'])->toBe('new@example.com')
        ->and($fresh->imap_config['password'])->toBe('imap-secret-pw')
        ->and($fresh->smtp_config['encryption'])->toBe('ssl')
        ->and($fresh->smtp_config['password'])->toBe('smtp-secret-pw')
        ->and($fresh->smtp_config)->not->toHaveKey('has_password');
});

test('a new password on update replaces the stored one', function () {
    $mailbox = mailboxWithCredentials($this->workspace->id);

    $this->actingAs($this->admin)
        ->patch("/mailboxes/{$mailbox->id}", [
            'smtp_config' => [
                'host' => 'smtp.gmail.com',
                'port' => '587',
                'encryption' => 'tls',
                'username' => 'support@example.com',
                'password' => 'rotated-pw',
            ],
        ])
        ->assertRedirect();

    expect($mailbox->fresh()->smtp_config['password'])->toBe('rotated-pw')
        ->and($mailbox->fresh()->imap_config['password'])->toBe('imap-secret-pw');
});

test('mailbox update rejects unknown encryption values', function () {
    $mailbox = mailboxWithCredentials($this->workspace->id);

    $this->actingAs($this->admin)
        ->patch("/mailboxes/{$mailbox->id}", [
            'smtp_config' => ['host' => 'smtp.gmail.com', 'port' => '587', 'encryption' => 'starttls-ish'],
        ])
        ->assertSessionHasErrors(['smtp_config.encryption']);
});

// ── aliases ────────────────────────────────────────────────────────────────────

test('admin can set mailbox aliases, which are trimmed and lower-cased', function () {
    $mailbox = Mailbox::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->admin)
        ->patch("/mailboxes/{$mailbox->id}", ['aliases' => " Orders@Example.com \n\nhello@example.com"])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($mailbox->fresh()->aliases)->toBe(['orders@example.com', 'hello@example.com']);

    $this->actingAs($this->admin)
        ->patch("/mailboxes/{$mailbox->id}", ['aliases' => []])
        ->assertSessionHasNoErrors();

    expect($mailbox->fresh()->aliases)->toBe([]);
});

test('mailbox aliases must be valid, distinct email addresses', function () {
    $mailbox = Mailbox::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->admin)
        ->patch("/mailboxes/{$mailbox->id}", ['aliases' => ['not-an-address']])
        ->assertSessionHasErrors(['aliases.0']);

    $this->actingAs($this->admin)
        ->patch("/mailboxes/{$mailbox->id}", ['aliases' => ['a@example.com', 'A@example.com']])
        ->assertSessionHasErrors(['aliases.0']);
});

test('the mailbox settings page receives the aliases', function () {
    $mailbox = Mailbox::factory()->create(['workspace_id' => $this->workspace->id, 'aliases' => ['orders@example.com']]);

    $this->actingAs($this->admin)
        ->get("/mailboxes/{$mailbox->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('mailbox.aliases', ['orders@example.com']));
});
