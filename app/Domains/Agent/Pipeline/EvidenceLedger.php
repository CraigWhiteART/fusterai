<?php

namespace App\Domains\Agent\Pipeline;

/**
 * Every fact the writer may state, each with an id (E1, E2, ...) that claims
 * cite. Notes explain what is missing (not citable); guidance holds the
 * shipment rules derived in code. Serialisable, so a run can be replayed
 * against exactly the evidence it saw, and a simulation can supply its own.
 */
final class EvidenceLedger
{
    /** @var list<array{id: string, source: string, key: string, label: string, value: string}> */
    private array $facts = [];

    /** @var list<string> */
    private array $notes = [];

    /** @var list<string> */
    private array $guidance = [];

    /** Untrusted free text attached to the evidence (e.g. Shopify order notes). */
    private ?string $untrustedNotes = null;

    /** @var array<string, mixed> */
    private array $meta = [];

    public function add(string $source, string $key, string $label, string $value): string
    {
        foreach ($this->facts as $fact) {
            if ($fact['key'] === $key && $fact['value'] === $value) {
                return $fact['id'];
            }
        }

        $id = 'E'.(count($this->facts) + 1);
        $this->facts[] = ['id' => $id, 'source' => $source, 'key' => $key, 'label' => $label, 'value' => $value];

        return $id;
    }

    /** @param  list<array{key: string, label: string, value: string}>  $facts */
    public function addMany(string $source, array $facts): void
    {
        foreach ($facts as $fact) {
            $this->add($source, $fact['key'], $fact['label'], $fact['value']);
        }
    }

    public function note(string $note): void
    {
        if (! in_array($note, $this->notes, true)) {
            $this->notes[] = $note;
        }
    }

    public function guide(string $guidance): void
    {
        if (! in_array($guidance, $this->guidance, true)) {
            $this->guidance[] = $guidance;
        }
    }

    public function setUntrustedNotes(?string $notes): void
    {
        $this->untrustedNotes = $notes;
    }

    public function untrustedNotes(): ?string
    {
        return $this->untrustedNotes;
    }

    public function setMeta(string $key, mixed $value): void
    {
        $this->meta[$key] = $value;
    }

    public function meta(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }

    public function has(string $id): bool
    {
        foreach ($this->facts as $fact) {
            if ($fact['id'] === $id) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{id: string, source: string, key: string, label: string, value: string}> */
    public function facts(?string $source = null): array
    {
        if ($source === null) {
            return $this->facts;
        }

        return array_values(array_filter($this->facts, fn ($fact) => $fact['source'] === $source));
    }

    public function valueOf(string $key): ?string
    {
        foreach ($this->facts as $fact) {
            if ($fact['key'] === $key) {
                return $fact['value'];
            }
        }

        return null;
    }

    /** @return list<string> */
    public function notes(): array
    {
        return $this->notes;
    }

    /** @return list<string> */
    public function guidance(): array
    {
        return $this->guidance;
    }

    /** All fact values as one string, for grounding checks. */
    public function corpus(): string
    {
        return implode("\n", array_map(fn ($fact) => $fact['value'], $this->facts));
    }

    /** Label-value lines with ids, for the writer and validator. */
    public function toPromptText(): string
    {
        $lines = [];
        foreach ($this->facts as $fact) {
            $lines[] = "[{$fact['id']}] ({$fact['source']}) {$fact['label']}: {$fact['value']}";
        }
        if ($lines === []) {
            $lines[] = '(no verified facts)';
        }
        foreach ($this->notes as $note) {
            $lines[] = 'NOTE: '.$note;
        }

        return implode("\n", $lines);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'facts' => $this->facts,
            'notes' => $this->notes,
            'guidance' => $this->guidance,
            'untrusted_notes' => $this->untrustedNotes,
            'meta' => $this->meta,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $ledger = new self;
        foreach ($data['facts'] ?? [] as $fact) {
            $ledger->add(
                (string) ($fact['source'] ?? 'supplied'),
                (string) ($fact['key'] ?? 'supplied.'.count($ledger->facts)),
                (string) ($fact['label'] ?? 'Fact'),
                (string) ($fact['value'] ?? ''),
            );
        }
        foreach ($data['notes'] ?? [] as $note) {
            $ledger->note((string) $note);
        }
        foreach ($data['guidance'] ?? [] as $guidance) {
            $ledger->guide((string) $guidance);
        }
        $ledger->untrustedNotes = isset($data['untrusted_notes']) ? (string) $data['untrusted_notes'] : null;
        $ledger->meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];

        return $ledger;
    }
}
