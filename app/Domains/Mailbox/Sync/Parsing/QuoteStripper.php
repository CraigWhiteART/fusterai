<?php

namespace App\Domains\Mailbox\Sync\Parsing;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Extract the new content of a reply: drop quoted history, reply headers and
 * signatures, keep paragraph breaks. Output is plain text; the raw HTML/text
 * bodies are stored separately and never modified.
 *
 * Replaces ProcessInboundEmailJob::stripQuotedReply(), whose `On .* wrote:`
 * with /s deleted from the first "On" anywhere in the message to the last
 * "wrote:", and which deleted every <blockquote> including ones the customer
 * wrote. Here:
 *  - HTML quote containers are removed by structure (gmail_quote,
 *    blockquote[type=cite], Outlook #appendonsend / #divRplyFwdMsg,
 *    yahoo_quoted, moz-cite-prefix), not by tag name;
 *  - an attribution "On ... wrote:" must start a line and end within 3 lines;
 *  - Outlook "From:/Sent:/To:/Subject:" needs at least three of those headers
 *    on consecutive lines;
 *  - if stripping would leave nothing, the full text is returned instead.
 */
final class QuoteStripper
{
    /** "On <date>, <name> wrote:" in the languages customers actually use. */
    private const ATTRIBUTION = '/^\s*(?:On|Le|Am|El|Il|Op|Em|Den|På)\s.{0,300}?(?:wrote|a écrit|schrieb|escribió|ha scritto|schreef|escreveu|skrev)\s*:\s*$/iu';

    private const ORIGINAL_MESSAGE = '/^\s*-{2,}\s*(?:Original Message|Ursprüngliche Nachricht|Message d\'origine|Mensaje original|Messaggio originale)\s*-{2,}\s*$/iu';

    private const HEADER_LINE = '/^\s*\*?(From|Sent|Date|To|Cc|Subject|De|Envoyé|À|Objet|Von|Gesendet|An|Betreff)\s*:\*?\s*\S/iu';

    private const MOBILE_SIGNATURE = '/^\s*(?:Sent from my (?:iPhone|iPad|Android|Samsung|mobile)[^\n]*|Get Outlook for (?:iOS|Android)[^\n]*|Sent from (?:Mail|Yahoo Mail|Outlook) for [^\n]*)\s*$/iu';

    public function clean(?string $html, ?string $text): string
    {
        if ($html !== null && trim($html) !== '') {
            $clean = $this->fromHtml($html);
            if ($clean !== '') {
                return $clean;
            }
        }

        if ($text !== null && trim($text) !== '') {
            return $this->fromText($text);
        }

        return '';
    }

    public function fromHtml(string $html): string
    {
        $doc = HtmlToText::load($html);
        if ($doc === null) {
            return $this->fromText(strip_tags($html));
        }

        $full = HtmlToText::fromDocument($doc);

        $this->removeQuoteContainers($doc);
        $clean = $this->stripText(HtmlToText::fromDocument($doc));

        return $clean !== '' ? $clean : $full;
    }

    public function fromText(string $text): string
    {
        $full = $this->normalise($text);
        $clean = $this->stripText($full);

        return $clean !== '' ? $clean : trim($full);
    }

    /** Line-based stripping shared by both paths. */
    private function stripText(string $text): string
    {
        $lines = explode("\n", $this->normalise($text));

        $cut = $this->findCut($lines);
        if ($cut !== null) {
            $lines = array_slice($lines, 0, $cut);
        }

        // Inline quoting: drop every ">"-prefixed line, keep interleaved answers.
        $lines = array_values(array_filter($lines, fn (string $l) => ! preg_match('/^\s*>/', $l)));

        $lines = $this->stripSignature($lines);

        $out = implode("\n", $lines);
        $out = (string) preg_replace("/\n{3,}/", "\n\n", $out);

        return trim($out);
    }

    /**
     * First line where quoted history starts. If nothing is above it the
     * caller ends up empty and falls back to the full text.
     *
     * @param  list<string>  $lines
     */
    private function findCut(array $lines): ?int
    {
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            if (preg_match(self::ORIGINAL_MESSAGE, $lines[$i])) {
                return $i;
            }

            // Attribution, possibly wrapped by the sender's client onto 2-3 lines.
            if (preg_match('/^\s*(?:On|Le|Am|El|Il|Op|Em|Den|På)\s/iu', $lines[$i])) {
                $joined = $lines[$i];
                for ($j = 0; $j < 3 && $i + $j < $count; $j++) {
                    if ($j > 0) {
                        // A wrapped attribution never has a blank line or a
                        // finished sentence in the middle.
                        if (trim($lines[$i + $j]) === '' || preg_match('/[.!?]\s*$/', $lines[$i + $j - 1])) {
                            break;
                        }
                        $joined .= ' '.trim($lines[$i + $j]);
                    }
                    // Real attributions carry a date or an address.
                    if (preg_match(self::ATTRIBUTION, $joined) && preg_match('/\d|@/', $joined)) {
                        return $i;
                    }
                }
            }

            if ($this->isHeaderBlock($lines, $i)) {
                // Outlook often puts a rule line (________) right above "From:".
                return ($i > 0 && preg_match('/^\s*_{5,}\s*$/', $lines[$i - 1])) ? $i - 1 : $i;
            }
        }

        return null;
    }

    /** @param list<string> $lines */
    private function isHeaderBlock(array $lines, int $i): bool
    {
        if (! preg_match(self::HEADER_LINE, $lines[$i], $m) || ! in_array(strtolower($m[1]), ['from', 'de', 'von'], true)) {
            return false;
        }

        $seen = [];
        for ($j = $i; $j < min(count($lines), $i + 6); $j++) {
            if (trim($lines[$j]) === '') {
                continue;
            }
            if (! preg_match(self::HEADER_LINE, $lines[$j], $h)) {
                break;
            }
            $seen[strtolower($h[1])] = true;
        }

        return count($seen) >= 3;
    }

    /** @param list<string> $lines */
    private function hasContentBefore(array $lines, int $i): bool
    {
        for ($k = 0; $k < $i; $k++) {
            $l = trim($lines[$k]);
            if ($l !== '' && $l[0] !== '>') {
                return true;
            }
        }

        return false;
    }

    /**
     * Cut at an RFC 3676 signature delimiter ("-- ") and drop a trailing
     * "Sent from my iPhone", unless that would remove everything.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function stripSignature(array $lines): array
    {
        foreach ($lines as $i => $line) {
            if (preg_match('/^--\s?$/', $line) && $this->hasContentBefore($lines, $i)) {
                $lines = array_slice($lines, 0, $i);
                break;
            }
        }

        while ($lines !== [] && trim((string) end($lines)) === '') {
            array_pop($lines);
        }
        if ($lines !== [] && preg_match(self::MOBILE_SIGNATURE, (string) end($lines)) && $this->hasContentBefore($lines, count($lines) - 1)) {
            array_pop($lines);
        }

        return array_values($lines);
    }

    private function normalise(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}", "\u{FEFF}"], ["\n", "\n", ' ', ''], $text);
        $text = implode("\n", array_map('rtrim', explode("\n", $text)));

        return (string) preg_replace("/\n{3,}/", "\n\n", $text);
    }

    private function removeQuoteContainers(DOMDocument $doc): void
    {
        $xpath = new DOMXPath($doc);

        $selectors = [
            // Gmail: <div class="gmail_quote"> (incl. gmail_quote_container) and its attribution.
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' gmail_quote') or contains(@class, 'gmail_quote_container')]",
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' gmail_attr ')]",
            "//*[contains(@class, 'gmail_signature') or @data-smartmail='gmail_signature']",
            // Apple Mail / Thunderbird.
            "//blockquote[@type='cite']",
            "//*[contains(@class, 'moz-cite-prefix')]",
            "//*[contains(@class, 'moz-signature')]",
            // Yahoo.
            "//*[contains(@class, 'yahoo_quoted')]",
        ];
        foreach ($selectors as $selector) {
            $this->removeAll($xpath, $selector, false);
        }

        // Outlook: everything from these markers to the end of their parent is history.
        $this->removeAll($xpath, "//*[@id='appendonsend' or @id='divRplyFwdMsg' or starts-with(@id, 'x_divRplyFwdMsg') or @id='mail-editor-reference-message-container']", true);
    }

    private function removeAll(DOMXPath $xpath, string $selector, bool $withFollowing): void
    {
        $nodes = $xpath->query($selector);
        if ($nodes === false) {
            return;
        }

        /** @var list<DOMNode> $list */
        $list = iterator_to_array($nodes);
        foreach ($list as $node) {
            if ($node->parentNode === null) {
                continue; // already removed with an ancestor
            }
            if ($withFollowing) {
                while ($node->nextSibling !== null) {
                    $node->parentNode->removeChild($node->nextSibling);
                }
            }
            if ($node instanceof DOMElement && $node->parentNode !== null) {
                $node->parentNode->removeChild($node);
            }
        }
    }
}
