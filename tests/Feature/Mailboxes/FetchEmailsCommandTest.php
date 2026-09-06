<?php

use App\Domains\Mailbox\Models\Mailbox;
use App\Domains\Mailbox\Support\ImapInboundMessage;
use App\Models\Workspace;

test('emails:fetch reports when no imap mailboxes exist', function () {
    $this->artisan('emails:fetch')
        ->expectsOutput('No active IMAP mailboxes found.')
        ->assertSuccessful();
});

test('emails:fetch skips mailboxes with a blank imap host', function () {
    $workspace = Workspace::factory()->create();
    Mailbox::factory()->create([
        'workspace_id' => $workspace->id,
        'email' => 'support@example.com',
        'active' => true,
        'imap_config' => [
            'host' => '',
            'port' => '993',
            'encryption' => 'ssl',
            'username' => 'support@example.com',
            'password' => 'app-password-here',
        ],
    ]);

    $this->artisan('emails:fetch')
        ->expectsOutputToContain('IMAP host/username missing')
        ->assertSuccessful();
});

test('oversize attachments are dropped from the inbound payload', function () {
    $small = new class
    {
        public function getName(): string
        {
            return 'ok.txt';
        }

        public function getContent(): string
        {
            return 'hello';
        }

        public function getMimeType(): string
        {
            return 'text/plain';
        }

        public function getSize(): int
        {
            return 5;
        }
    };

    $huge = new class
    {
        public function getName(): string
        {
            return 'dump.bin';
        }

        public function getContent(): string
        {
            return str_repeat('a', 100);
        }

        public function getMimeType(): string
        {
            return 'application/octet-stream';
        }

        public function getSize(): int
        {
            return 50_000_000;
        }
    };

    $kept = ImapInboundMessage::filterAttachments([$small, $huge], maxBytes: 1_000);

    expect($kept)->toHaveCount(1)
        ->and($kept[0]['name'])->toBe('ok.txt')
        ->and(base64_decode($kept[0]['content']))->toBe('hello');
});
