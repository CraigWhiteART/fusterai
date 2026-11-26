<?php

use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Customer\Models\Customer;
use App\Domains\Mailbox\Models\Mailbox;
use App\Domains\Mailbox\Sync\Enums\MailDirection;
use App\Domains\Mailbox\Sync\Models\MailMessage;
use App\Domains\Mailbox\Sync\Parsing\MessageParser;
use App\Domains\Mailbox\Sync\Parsing\ParsedMessage;
use App\Domains\Mailbox\Sync\Threading\MailboxIdentity;
use App\Domains\Mailbox\Sync\Threading\ThreadDecision;
use App\Domains\Mailbox\Sync\Threading\ThreadResolver;

function resolverFixtureRaw(string $file): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/mail/'.$file));
}

function resolverParse(string $raw): ParsedMessage
{
    return (new MessageParser)->parse($raw);
}

function resolverLedgerRow(Mailbox $mailbox, array $attributes): MailMessage
{
    static $uid = 1;

    return MailMessage::query()->create(array_merge([
        'mailbox_id' => $mailbox->id,
        'folder' => '[Gmail]/All Mail',
        'uidvalidity' => 1,
        'uid' => $uid++,
        'direction' => MailDirection::Inbound,
    ], $attributes));
}

function resolverConversation(Mailbox $mailbox, string $customerEmail): Conversation
{
    $customer = Customer::factory()->create(['workspace_id' => $mailbox->workspace_id, 'email' => $customerEmail]);

    return Conversation::factory()->create([
        'workspace_id' => $mailbox->workspace_id,
        'mailbox_id' => $mailbox->id,
        'customer_id' => $customer->id,
    ]);
}

beforeEach(function () {
    $this->mailbox = Mailbox::factory()->create(['email' => 'support@souldial.test']);
    $this->identity = MailboxIdentity::forMailbox($this->mailbox, ['sam@souldial.test']);
    $this->resolver = new ThreadResolver;
});

test('direction and customer for inbound, outbound, contact-form and internal mail', function () {
    $r = $this->resolver;
    $id = $this->identity;

    $enquiry = resolverParse(resolverFixtureRaw('01-customer-enquiry.eml'));
    $reply = resolverParse(resolverFixtureRaw('02-agent-reply-from-gmail.eml'));
    $form = resolverParse(resolverFixtureRaw('04-shopify-contact-form.eml'));
    $internal = resolverParse("From: Sam <Sam+notes@souldial.test>\r\nTo: support@souldial.test\r\nSubject: note\r\n\r\nx");
    $alias = resolverParse("From: sam@souldial.test\r\nTo: Emma <emma.clarke@example.net>\r\nCc: support@souldial.test\r\nSubject: Re: hi\r\n\r\nx");

    expect($r->direction($enquiry, $id))->toBe(MailDirection::Inbound)
        ->and($r->customer($enquiry, $id)['email'])->toBe('emma.clarke@example.net')
        ->and($r->direction($reply, $id))->toBe(MailDirection::Outbound)
        ->and($r->customer($reply, $id)['email'])->toBe('emma.clarke@example.net')
        ->and($r->direction($form, $id))->toBe(MailDirection::Inbound)
        ->and($r->customer($form, $id))->toBe(['email' => 'liam.patel@example.org', 'name' => 'Liam Patel'])
        ->and($r->direction($internal, $id))->toBe(MailDirection::Internal)
        ->and($r->customer($internal, $id))->toBeNull()
        ->and($r->direction($alias, $id))->toBe(MailDirection::Outbound)
        ->and($r->customer($alias, $id)['email'])->toBe('emma.clarke@example.net');
});

test('Reply-To does not override a normal customer sender', function () {
    $m = resolverParse("From: Emma <emma.clarke@example.net>\r\nReply-To: emma.work@corp.example\r\nTo: support@souldial.test\r\nSubject: hi\r\n\r\nx");

    expect($this->resolver->customer($m, $this->identity)['email'])->toBe('emma.clarke@example.net');
});

test('a first message with no links is a new conversation', function () {
    $decision = $this->resolver->resolve($this->mailbox->id, $this->identity, resolverParse(resolverFixtureRaw('01-customer-enquiry.eml')), '1847650000000000101', '1847650000000000101');

    expect($decision->matchedBy)->toBe(ThreadDecision::MATCH_NONE)
        ->and($decision->isNewConversation())->toBeTrue()
        ->and($decision->customerEmail)->toBe('emma.clarke@example.net')
        ->and($decision->customerName)->toBe('Emma Clarke');
});

test('gmail thread id matches when the customer is the same', function () {
    $conversation = resolverConversation($this->mailbox, 'emma.clarke@example.net');
    resolverLedgerRow($this->mailbox, [
        'gm_msgid' => '1847650000000000101', 'gm_thrid' => '1847650000000000101',
        'message_id' => 'CAEmma001.k3j2@mail.gmail.com', 'customer_email' => 'emma.clarke@example.net',
        'conversation_id' => $conversation->id,
    ]);

    // Strip threading headers so only the Gmail thread id can link it.
    $raw = preg_replace('/^(References|In-Reply-To):.*\R( .*\R)?/mi', '', resolverFixtureRaw('03-customer-followup-top-posted.eml'));
    $decision = $this->resolver->resolve($this->mailbox->id, $this->identity, resolverParse($raw), '1847650000000000101', '1847650000000000106');

    expect($decision->matchedBy)->toBe(ThreadDecision::MATCH_GM_THREAD)
        ->and($decision->conversationId)->toBe($conversation->id);
});

test('gmail-merged contact form threads stay separate per customer', function () {
    $liam = resolverConversation($this->mailbox, 'liam.patel@example.org');
    resolverLedgerRow($this->mailbox, [
        'gm_msgid' => '1847650000000000103', 'gm_thrid' => '1847650000000000103',
        'message_id' => '6907c2b1a_3f1e2@shopify-mailer-7c9d.mail', 'customer_email' => 'liam.patel@example.org',
        'conversation_id' => $liam->id,
    ]);

    $decision = $this->resolver->resolve(
        $this->mailbox->id, $this->identity,
        resolverParse(resolverFixtureRaw('04b-shopify-contact-form-other-customer.eml')),
        '1847650000000000103', '1847650000000000104',
    );

    expect($decision->isNewConversation())->toBeTrue()
        ->and($decision->customerEmail)->toBe('aiko.tanaka@example.jp')
        ->and($decision->notes)->toContain('gmail thread belongs to a different customer; not merged');
});

test('In-Reply-To / References match message ids already in the ledger', function () {
    $conversation = resolverConversation($this->mailbox, 'emma.clarke@example.net');
    resolverLedgerRow($this->mailbox, [
        'message_id' => 'CAAgent002.q9w8@mail.gmail.com', 'direction' => MailDirection::Outbound,
        'customer_email' => 'emma.clarke@example.net', 'conversation_id' => $conversation->id,
    ]);

    $decision = $this->resolver->resolve($this->mailbox->id, $this->identity, resolverParse(resolverFixtureRaw('03-customer-followup-top-posted.eml')));

    expect($decision->matchedBy)->toBe(ThreadDecision::MATCH_REFERENCES)
        ->and($decision->conversationId)->toBe($conversation->id);
});

test('legacy conversation-N@fusterai reference still threads', function () {
    $conversation = resolverConversation($this->mailbox, 'juergen.mueller@posteo.example');
    $raw = str_replace(
        ['conversation-31@fusterai', 'thread-58-'],
        ["conversation-{$conversation->id}@fusterai", 'thread-999999-'],
        resolverFixtureRaw('08-plain-text-quoted.eml'),
    );

    $decision = $this->resolver->resolve($this->mailbox->id, $this->identity, resolverParse($raw));

    expect($decision->matchedBy)->toBe(ThreadDecision::MATCH_LEGACY_CONVERSATION)
        ->and($decision->conversationId)->toBe($conversation->id);
});

test('legacy thread-N-uuid@fusterai reference resolves through the thread', function () {
    $conversation = resolverConversation($this->mailbox, 'juergen.mueller@posteo.example');
    $thread = Thread::factory()->create(['conversation_id' => $conversation->id]);
    $raw = str_replace('thread-58-', "thread-{$thread->id}-", resolverFixtureRaw('08-plain-text-quoted.eml'));

    $decision = $this->resolver->resolve($this->mailbox->id, $this->identity, resolverParse($raw));

    expect($decision->matchedBy)->toBe(ThreadDecision::MATCH_LEGACY_THREAD)
        ->and($decision->conversationId)->toBe($conversation->id)
        ->and($decision->existingThreadId)->toBeNull();
});

test('legacy ids never cross into another mailbox', function () {
    $other = Mailbox::factory()->create();
    $foreign = resolverConversation($other, 'juergen.mueller@posteo.example');
    $raw = str_replace(['conversation-31@fusterai', 'thread-58-'], ["conversation-{$foreign->id}@fusterai", 'thread-999999-'], resolverFixtureRaw('08-plain-text-quoted.eml'));

    $decision = $this->resolver->resolve($this->mailbox->id, $this->identity, resolverParse($raw));

    expect($decision->isNewConversation())->toBeTrue();
});

test('the Sent copy of a fusterai reply is recognised as an existing thread', function () {
    $conversation = resolverConversation($this->mailbox, 'emma.clarke@example.net');
    $thread = Thread::factory()->create(['conversation_id' => $conversation->id]);
    $raw = "Message-ID: <thread-{$thread->id}-0b6f3a1e@fusterai>\r\nFrom: support@souldial.test\r\nTo: emma.clarke@example.net\r\nSubject: Re: x\r\n\r\nHello";

    $decision = $this->resolver->resolve($this->mailbox->id, $this->identity, resolverParse($raw), '1', '2');

    expect($decision->direction)->toBe(MailDirection::Outbound)
        ->and($decision->existingThreadId)->toBe($thread->id)
        ->and($decision->conversationId)->toBe($conversation->id);
});

test('a message already linked in the ledger resolves to its conversation', function () {
    $conversation = resolverConversation($this->mailbox, 'emma.clarke@example.net');
    resolverLedgerRow($this->mailbox, [
        'gm_msgid' => '1847650000000000106', 'gm_thrid' => '1847650000000000101',
        'message_id' => 'CAEmma003.z7x6@mail.gmail.com', 'conversation_id' => $conversation->id,
    ]);

    $decision = $this->resolver->resolve($this->mailbox->id, $this->identity, resolverParse(resolverFixtureRaw('03-customer-followup-top-posted.eml')), '1847650000000000101', '1847650000000000106');

    expect($decision->matchedBy)->toBe(ThreadDecision::MATCH_ALREADY_LINKED)
        ->and($decision->conversationId)->toBe($conversation->id);
});

test('a soft-deleted conversation is not reused', function () {
    $conversation = resolverConversation($this->mailbox, 'emma.clarke@example.net');
    resolverLedgerRow($this->mailbox, [
        'gm_msgid' => '1847650000000000101', 'gm_thrid' => '1847650000000000101',
        'customer_email' => 'emma.clarke@example.net', 'conversation_id' => $conversation->id,
    ]);
    $conversation->delete();

    $decision = $this->resolver->resolve($this->mailbox->id, $this->identity, resolverParse(resolverFixtureRaw('03-customer-followup-top-posted.eml')), '1847650000000000101', '1847650000000000106');

    expect($decision->isNewConversation())->toBeTrue();
});

test('resolving never creates conversations or ledger rows', function () {
    $before = [Conversation::count(), MailMessage::count()];

    $this->resolver->resolve($this->mailbox->id, $this->identity, resolverParse(resolverFixtureRaw('01-customer-enquiry.eml')));

    expect([Conversation::count(), MailMessage::count()])->toBe($before);
});
