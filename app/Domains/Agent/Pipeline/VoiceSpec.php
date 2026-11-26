<?php

namespace App\Domains\Agent\Pipeline;

use App\Domains\Agent\Models\VoiceVersion;
use App\Domains\Agent\Services\VersionService;

final class VoiceSpec
{
    /**
     * @param  list<array{id: string, text: string}>  $doPhrases
     * @param  list<array{id: string, text: string}>  $dontPhrases
     * @param  list<array{id: string, text: string}>  $greetings
     * @param  list<array{id: string, text: string}>  $signOffs
     */
    public function __construct(
        public readonly ?int $versionId,
        public readonly string $personality,
        public readonly array $doPhrases = [],
        public readonly array $dontPhrases = [],
        public readonly array $greetings = [],
        public readonly array $signOffs = [],
        public readonly ?int $maxWords = null,
        public readonly ?string $lengthGuidance = null,
        public readonly bool $overlaid = false,
    ) {}

    public static function fromModel(VoiceVersion $voice): self
    {
        return new self(
            versionId: $voice->id,
            personality: $voice->personality,
            doPhrases: array_values($voice->do_phrases ?? []),
            dontPhrases: array_values($voice->dont_phrases ?? []),
            greetings: array_values($voice->greetings ?? []),
            signOffs: array_values($voice->sign_offs ?? []),
            maxWords: $voice->max_words,
            lengthGuidance: $voice->length_guidance,
        );
    }

    /** Used when a workspace has no voice yet. */
    public static function fallback(): self
    {
        return new self(versionId: null, personality: 'Friendly, direct and concise.');
    }

    /** @param  array<string, mixed>  $overlay */
    public function withOverlay(array $overlay): self
    {
        return new self(
            versionId: null,
            personality: (string) ($overlay['personality'] ?? $this->personality),
            doPhrases: isset($overlay['do_phrases']) ? self::items($overlay['do_phrases']) : $this->doPhrases,
            dontPhrases: isset($overlay['dont_phrases']) ? self::items($overlay['dont_phrases']) : $this->dontPhrases,
            greetings: isset($overlay['greetings']) ? self::items($overlay['greetings']) : $this->greetings,
            signOffs: isset($overlay['sign_offs']) ? self::items($overlay['sign_offs']) : $this->signOffs,
            maxWords: array_key_exists('max_words', $overlay) ? ($overlay['max_words'] === null ? null : (int) $overlay['max_words']) : $this->maxWords,
            lengthGuidance: array_key_exists('length_guidance', $overlay) ? $overlay['length_guidance'] : $this->lengthGuidance,
            overlaid: true,
        );
    }

    /**
     * Overlay items come from callers (preview, manager), not the database:
     * normalise them to [{id, text}].
     *
     * @return list<array{id: string, text: string}>
     */
    private static function items(mixed $items): array
    {
        return app(VersionService::class)->items($items, 'overlay');
    }

    /** @return list<string> */
    public function dontTexts(): array
    {
        return array_values(array_filter(array_map(fn ($item) => trim((string) $item['text']), $this->dontPhrases)));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version_id' => $this->versionId,
            'personality' => $this->personality,
            'do_phrases' => $this->doPhrases,
            'dont_phrases' => $this->dontPhrases,
            'greetings' => $this->greetings,
            'sign_offs' => $this->signOffs,
            'max_words' => $this->maxWords,
            'length_guidance' => $this->lengthGuidance,
            'overlaid' => $this->overlaid,
        ];
    }
}
