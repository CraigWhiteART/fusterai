<?php

namespace App\Domains\Agent\Pipeline;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Conversation\Models\Conversation;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Everything one run reads about the case. Built either from a live
 * conversation, or from a supplied transcript + evidence + "now" (frozen
 * case, simulation, preview), in which case nothing live is touched.
 */
final class PipelineInput
{
    /**
     * @param  list<string>  $replyToEmails
     */
    public function __construct(
        public readonly int $workspaceId,
        public readonly string $purpose,
        public readonly Transcript $transcript,
        public readonly CarbonImmutable $now,
        public readonly string $subject = '',
        public readonly ?string $customerName = null,
        public readonly ?string $customerEmail = null,
        public readonly array $replyToEmails = [],
        public readonly ?Conversation $conversation = null,
        public readonly ?EvidenceLedger $suppliedEvidence = null,
        public readonly ?int $frozenCaseId = null,
        public readonly bool $force = false,
        // A person asked for this draft ("AI Suggest") while the agent is off /
        // in shadow: a one-off live draft; never auto-sent (force => requested_by_human).
        public readonly bool $manual = false,
    ) {
        if (! in_array($purpose, AgentRun::PURPOSES, true)) {
            throw new InvalidArgumentException("Unknown run purpose [{$purpose}].");
        }
        if ($purpose === AgentRun::PURPOSE_LIVE && $conversation === null) {
            throw new InvalidArgumentException('A live run needs a conversation.');
        }
    }

    /**
     * @param  bool  $force  Regenerate: skip the "already ran for this message" idempotency reuse.
     */
    public static function fromConversation(Conversation $conversation, string $purpose = AgentRun::PURPOSE_LIVE, bool $force = false, ?CarbonImmutable $now = null, bool $manual = false): self
    {
        $conversation->loadMissing('customer');
        $transcript = app(TranscriptBuilder::class)->fromConversation($conversation);

        return new self(
            workspaceId: (int) $conversation->workspace_id,
            purpose: $purpose,
            transcript: $transcript,
            now: $now ?? CarbonImmutable::now(),
            subject: (string) $conversation->subject,
            customerName: $conversation->customer?->name,
            customerEmail: $conversation->customer?->email,
            replyToEmails: self::replyToEmails($conversation),
            conversation: $conversation,
            force: $force || $manual,
            manual: $manual,
        );
    }

    /**
     * A frozen transcript with supplied evidence: no live conversation is read
     * or written, and no Shopify / carrier call is made.
     *
     * @param  list<TranscriptTurn|array<string, mixed>>  $turns
     * @param  list<string>  $replyToEmails
     */
    public static function fromTranscript(
        int $workspaceId,
        string $purpose,
        array $turns,
        EvidenceLedger $evidence,
        CarbonImmutable $now,
        string $subject = '',
        ?string $customerName = null,
        ?string $customerEmail = null,
        array $replyToEmails = [],
        ?int $frozenCaseId = null,
    ): self {
        if ($purpose === AgentRun::PURPOSE_LIVE) {
            throw new InvalidArgumentException('A supplied transcript can never be a live run.');
        }

        $turns = array_map(fn ($turn) => $turn instanceof TranscriptTurn ? $turn : TranscriptTurn::fromArray($turn), $turns);

        return new self(
            workspaceId: $workspaceId,
            purpose: $purpose,
            transcript: new Transcript(array_values($turns)),
            now: $now,
            subject: $subject,
            customerName: $customerName,
            customerEmail: $customerEmail,
            replyToEmails: $replyToEmails,
            suppliedEvidence: $evidence,
            frozenCaseId: $frozenCaseId,
        );
    }

    public function isLive(): bool
    {
        return $this->purpose === AgentRun::PURPOSE_LIVE;
    }

    /** Addresses allowed to receive order details: the customer, plus any Reply-To customer. */
    public function senderEmails(): array
    {
        $emails = array_merge([$this->customerEmail], $this->replyToEmails);

        return array_values(array_unique(array_filter(array_map(
            fn ($email) => mb_strtolower(trim((string) $email)),
            $emails,
        ))));
    }

    /** @return list<string> */
    private static function replyToEmails(Conversation $conversation): array
    {
        $emails = [];
        $threads = $conversation->threads()->whereNotNull('customer_id')->whereNotNull('mail_message_id')->with('mailMessage')->get();
        foreach ($threads as $thread) {
            foreach ($thread->mailMessage->reply_to ?? [] as $address) {
                if (is_array($address) && filled($address['email'] ?? null)) {
                    $emails[] = (string) $address['email'];
                }
            }
        }

        return array_values(array_unique($emails));
    }
}
