<?php

namespace App\Domains\Agent\Manager;

use App\Domains\Agent\Pipeline\UntrustedData;
use Closure;

/**
 * State shared by the manager's tools during one turn: limits, what was
 * looked at (citations), what was staged, and what was refused. Tools never
 * write anything except through stage_proposal (ProposalService, draft
 * proposals only) and add_restriction (tightening the chat's scope).
 */
final class ManagerSession
{
    public int $calls = 0;

    public int $estimatedTokens = 0;

    public int $contextChars = 0;

    public bool $budgetReached = false;

    public bool $stepCapReached = false;

    public bool $deadlineReached = false;

    /** @var array<string, array{type: string, id: int, label: string, source: string}> */
    public array $citations = [];

    /** @var list<int> */
    public array $proposalIds = [];

    /** @var array<string, int> changes hash => proposal id (dedupe within a turn) */
    public array $stagedHashes = [];

    /** @var list<array<string, mixed>> */
    public array $suggestions = [];

    /** @var list<array<string, mixed>> */
    public array $toolLog = [];

    public readonly float $deadline;

    /**
     * @param  array<string, mixed>  $restrictions
     * @param  (Closure(array<string, mixed>): void)|null  $onStep
     */
    public function __construct(
        public readonly int $workspaceId,
        public readonly ?int $userId,
        public readonly int $chatId,
        public readonly int $messageId,
        public readonly string $request,
        public array $restrictions,
        public readonly UntrustedData $untrusted,
        public readonly int $stepCap,
        public readonly int $tokenBudget,
        int $turnSeconds,
        public readonly int $maxProposals,
        public readonly int $resultChars,
        public readonly ?Closure $onStep = null,
    ) {
        $this->deadline = microtime(true) + $turnSeconds;
    }

    /** Characters already in the context before the first model call. */
    public function prime(int $chars): void
    {
        $this->contextChars = $chars;
        $this->estimatedTokens = intdiv($chars, 4);
    }

    /**
     * Every tool call adds its arguments and result to the context, and the
     * next model call re-sends the whole context.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function charge(array $arguments, string $result): void
    {
        $this->contextChars += mb_strlen((string) json_encode($arguments)) + mb_strlen($result) + 200;
        $this->estimatedTokens += intdiv($this->contextChars, 4);
    }

    /** Why a lookup may not run now, or null. */
    public function blockedReason(bool $isStage = false): ?string
    {
        if (microtime(true) > $this->deadline) {
            $this->deadlineReached = true;

            return 'the time budget for this turn is used up';
        }
        if ($isStage) {
            return null;
        }
        if ($this->calls > $this->stepCap) {
            $this->stepCapReached = true;

            return "the step limit for this turn ({$this->stepCap} tool calls) is reached";
        }
        if ($this->estimatedTokens >= $this->tokenBudget) {
            $this->budgetReached = true;

            return 'the token budget for this turn is used up';
        }

        return null;
    }

    public function cite(string $type, int $id, string $label, string $source = 'read'): void
    {
        $key = "{$type}:{$id}";
        if (! isset($this->citations[$key])) {
            $this->citations[$key] = ['type' => $type, 'id' => $id, 'label' => mb_substr($label, 0, 160), 'source' => $source];
        }
    }

    /** @param  array<string, mixed>  $entry */
    public function step(array $entry): void
    {
        $this->toolLog[] = $entry;
        if ($this->onStep !== null) {
            ($this->onStep)($entry);
        }
    }
}
