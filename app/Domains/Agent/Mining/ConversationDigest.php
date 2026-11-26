<?php

namespace App\Domains\Agent\Mining;

use App\Domains\Agent\Pipeline\TranscriptTurn;
use Carbon\CarbonImmutable;

/**
 * One past conversation, read for mining: its turns (raw), which staff turns
 * a human wrote, the customer names to scrub, and the content hash that keys
 * its extraction.
 */
final class ConversationDigest
{
    /**
     * @param  list<TranscriptTurn>  $turns
     * @param  list<array{thread_id: int|null, text: string, at: CarbonImmutable, staff_key: string, staff_name: string}>  $humanReplies
     * @param  array<int, bool>  $humanThreadIds  thread id => true for human-written staff turns
     * @param  list<string>  $customerNames
     */
    public function __construct(
        public readonly int $conversationId,
        public readonly ?string $subject,
        public readonly array $turns,
        public readonly array $humanReplies,
        public readonly array $humanThreadIds,
        public readonly array $customerNames,
        public readonly string $contentHash,
    ) {}

    public function firstAt(): ?CarbonImmutable
    {
        return $this->turns[0]->at ?? null;
    }

    public function lastAt(): ?CarbonImmutable
    {
        return $this->turns === [] ? null : $this->turns[count($this->turns) - 1]->at;
    }

    public function lastStaffAt(): ?CarbonImmutable
    {
        return $this->humanReplies === [] ? null : $this->humanReplies[count($this->humanReplies) - 1]['at'];
    }

    /** @return list<string> */
    public function staffKeys(): array
    {
        return array_values(array_unique(array_column($this->humanReplies, 'staff_key')));
    }

    public function firstCustomerText(): string
    {
        foreach ($this->turns as $turn) {
            if ($turn->isCustomer() && trim($turn->text) !== '') {
                return $turn->text;
            }
        }

        return '';
    }

    public function isHumanTurn(TranscriptTurn $turn): bool
    {
        return ! $turn->isCustomer() && $turn->threadId !== null && isset($this->humanThreadIds[$turn->threadId]);
    }
}
