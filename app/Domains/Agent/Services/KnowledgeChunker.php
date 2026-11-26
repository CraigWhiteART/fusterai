<?php

namespace App\Domains\Agent\Services;

/**
 * Paragraph-aware chunking for knowledge-base documents: paragraphs are kept
 * whole and packed up to the chunk size; an over-long paragraph is split on
 * sentences; each chunk after the first starts with the tail of the previous
 * one (overlap) so a fact on a boundary is not lost.
 */
class KnowledgeChunker
{
    /** @return list<string> */
    public function chunk(string $content, ?int $size = null, ?int $overlap = null): array
    {
        $size = max(200, $size ?? (int) config('agent.knowledge.chunk_size', 1800));
        $overlap = max(0, min((int) ($size / 2), $overlap ?? (int) config('agent.knowledge.chunk_overlap', 200)));

        $paragraphs = $this->paragraphs($content);
        if ($paragraphs === []) {
            return [];
        }

        // Break over-long paragraphs into sentence groups first.
        $units = [];
        foreach ($paragraphs as $paragraph) {
            if (mb_strlen($paragraph) <= $size) {
                $units[] = $paragraph;

                continue;
            }
            foreach ($this->sentenceGroups($paragraph, $size) as $group) {
                $units[] = $group;
            }
        }

        $chunks = [];
        $current = '';
        foreach ($units as $unit) {
            $candidate = $current === '' ? $unit : $current."\n\n".$unit;
            if (mb_strlen($candidate) <= $size || $current === '') {
                $current = $candidate;

                continue;
            }

            $chunks[] = $current;
            $tail = $this->tail($current, $overlap);
            $current = $tail !== '' ? $tail."\n\n".$unit : $unit;
        }
        if (trim($current) !== '') {
            $chunks[] = $current;
        }

        return array_values(array_map('trim', $chunks));
    }

    /** HTML or plain text → paragraphs of normalised text. */
    public function paragraphs(string $content): array
    {
        $text = preg_replace('~<\s*br\s*/?>~i', "\n", $content) ?? $content;
        $text = preg_replace('~</\s*(p|div|li|h[1-6]|tr|blockquote|section|article)\s*>~i', "\n\n", $text) ?? $text;
        $text = preg_replace('~<\s*li[^>]*>~i', '- ', $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\r\n", "\n", $text);

        $paragraphs = [];
        foreach (preg_split('/\n\s*\n+/u', $text) ?: [] as $block) {
            $block = trim(preg_replace('/[ \t]+/u', ' ', $block) ?? $block);
            $block = preg_replace('/\n{2,}/u', "\n", $block) ?? $block;
            if ($block !== '') {
                $paragraphs[] = $block;
            }
        }

        return $paragraphs;
    }

    /** @return list<string> */
    private function sentenceGroups(string $paragraph, int $size): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', $paragraph) ?: [$paragraph];
        $groups = [];
        $current = '';

        foreach ($sentences as $sentence) {
            // A single sentence longer than the chunk: hard split.
            while (mb_strlen($sentence) > $size) {
                if ($current !== '') {
                    $groups[] = $current;
                    $current = '';
                }
                $groups[] = mb_substr($sentence, 0, $size);
                $sentence = mb_substr($sentence, $size);
            }

            $candidate = $current === '' ? $sentence : $current.' '.$sentence;
            if (mb_strlen($candidate) > $size && $current !== '') {
                $groups[] = $current;
                $current = $sentence;
            } else {
                $current = $candidate;
            }
        }
        if (trim($current) !== '') {
            $groups[] = $current;
        }

        return $groups;
    }

    private function tail(string $chunk, int $overlap): string
    {
        if ($overlap <= 0 || mb_strlen($chunk) <= $overlap) {
            return '';
        }

        $tail = mb_substr($chunk, -$overlap);
        // Start at a sentence or word boundary inside the tail.
        if (preg_match('/[.!?]\s+(.+)$/su', $tail, $m) === 1 && mb_strlen($m[1]) >= $overlap / 3) {
            return trim($m[1]);
        }
        $space = mb_strpos($tail, ' ');

        return trim($space !== false ? mb_substr($tail, $space + 1) : $tail);
    }
}
