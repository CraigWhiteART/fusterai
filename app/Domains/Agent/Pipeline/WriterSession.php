<?php

namespace App\Domains\Agent\Pipeline;

/**
 * Mutable state shared by the writer's tools during one run: what was looked
 * up, what was proposed, and the final submission. Tools never write to the
 * database or call a mutation; they only record here.
 */
final class WriterSession
{
    /** @var array<string, array{id: string, kind: string, title: string|null, body: string, scope: array<string, mixed>}> */
    public array $knowledge = [];

    /** @var list<array<string, mixed>> */
    public array $proposedActions = [];

    /** @var array{reason: string, detail: string}|null */
    public ?array $handover = null;

    /** @var array<string, mixed>|null */
    public ?array $submission = null;

    /** @var list<array<string, mixed>> */
    public array $toolLog = [];

    public function __construct(
        public readonly PipelineInput $input,
        public readonly InstructionSet $instructions,
        public readonly PlaybookSpec $playbook,
        public readonly EvidenceLedger $ledger,
        public readonly UntrustedData $untrusted,
        public readonly string $productHaystack,
        public readonly ?string $country,
    ) {}

    /** @param  list<array{id: string, kind: string, title: string|null, body: string, scope: array<string, mixed>}>  $items */
    public function rememberKnowledge(array $items): void
    {
        foreach ($items as $item) {
            $this->knowledge[$item['id']] = $item;
        }
    }

    /** @param  array<string, mixed>  $arguments */
    public function log(string $tool, array $arguments, string $result): void
    {
        $this->toolLog[] = [
            'tool' => $tool,
            'arguments' => $arguments,
            'result' => mb_substr($result, 0, 4000),
        ];
    }

    public function sourceExists(string $id): bool
    {
        return $this->ledger->has($id) || isset($this->knowledge[$id]);
    }
}
