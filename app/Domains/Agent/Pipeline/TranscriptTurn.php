<?php

namespace App\Domains\Agent\Pipeline;

use Carbon\CarbonImmutable;

/**
 * One message in a conversation, as the agent sees it. Built from a live
 * thread or supplied directly (frozen case / simulation).
 */
final class TranscriptTurn
{
    public const ROLE_CUSTOMER = 'customer';

    public const ROLE_STAFF = 'staff';

    public function __construct(
        public readonly string $role,
        public readonly string $text,
        public readonly CarbonImmutable $at,
        public readonly ?string $author = null,
        public readonly ?string $origin = null,
        public readonly ?int $threadId = null,
        public readonly bool $hasImages = false,
        public readonly bool $autoSubmitted = false,
    ) {}

    public function isCustomer(): bool
    {
        return $this->role === self::ROLE_CUSTOMER;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'text' => $this->text,
            'at' => $this->at->toIso8601String(),
            'author' => $this->author,
            'origin' => $this->origin,
            'thread_id' => $this->threadId,
            'has_images' => $this->hasImages,
            'auto_submitted' => $this->autoSubmitted,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            role: ($data['role'] ?? '') === self::ROLE_STAFF ? self::ROLE_STAFF : self::ROLE_CUSTOMER,
            text: (string) ($data['text'] ?? ''),
            at: CarbonImmutable::parse($data['at'] ?? 'now'),
            author: isset($data['author']) ? (string) $data['author'] : null,
            origin: isset($data['origin']) ? (string) $data['origin'] : null,
            threadId: isset($data['thread_id']) ? (int) $data['thread_id'] : null,
            hasImages: (bool) ($data['has_images'] ?? false),
            autoSubmitted: (bool) ($data['auto_submitted'] ?? false),
        );
    }
}
