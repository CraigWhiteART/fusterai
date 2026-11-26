<?php

namespace App\Domains\Mailbox\Sync\Parsing;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * HTML email to readable plain text with paragraph structure kept.
 * <style>, <script>, <head> and comments are dropped entirely (their text must
 * never leak into the body, unlike strip_tags()).
 */
final class HtmlToText
{
    private const SKIP = ['style', 'script', 'head', 'title', 'meta', 'link', 'noscript', 'template', 'svg', 'xml', 'o:p'];

    /** Elements that end a paragraph: followed by a blank line. */
    private const PARAGRAPH = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'table', 'blockquote', 'pre', 'hr'];

    /** Elements that start on a new line. */
    private const BLOCK = ['div', 'section', 'article', 'header', 'footer', 'li', 'tr', 'dl', 'dt', 'dd', 'center', 'address', 'main', 'nav', 'aside', 'figure', 'form'];

    public static function load(string $html): ?DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }

        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // The XML prolog makes libxml treat the input as UTF-8 regardless of <meta>.
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8"?>'.$html, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $doc : null;
    }

    public static function convert(string $html): string
    {
        $doc = self::load($html);

        return $doc === null ? '' : self::fromDocument($doc);
    }

    public static function fromDocument(DOMDocument $doc): string
    {
        $root = (new DOMXPath($doc))->query('//body')->item(0) ?? $doc->documentElement;
        if ($root === null) {
            return '';
        }

        $out = '';
        self::walk($root, $out, false);

        return self::tidy($out);
    }

    /** Collapse runs of blank lines, trim line ends, keep paragraph breaks. */
    public static function tidy(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}", "\u{200B}", "\u{FEFF}"], ["\n", "\n", ' ', '', ''], $text);
        $lines = array_map(fn (string $l) => rtrim((string) preg_replace('/[ \t]+/', ' ', $l)), explode("\n", $text));
        $lines = array_map(fn (string $l) => preg_match('/^ +[^ ]/', $l) ? ltrim($l) : $l, $lines);
        $text = implode("\n", $lines);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text, "\n ");
    }

    private static function walk(DOMNode $node, string &$out, bool $pre): void
    {
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $text = $child->textContent;
                $out .= $pre ? $text : (string) preg_replace('/\s+/u', ' ', $text);

                continue;
            }
            if (! $child instanceof DOMElement) {
                continue; // comments, processing instructions
            }

            $tag = strtolower($child->nodeName);
            if (in_array($tag, self::SKIP, true)) {
                continue;
            }

            if ($tag === 'br') {
                $out .= "\n";

                continue;
            }
            if ($tag === 'img') {
                continue;
            }

            $paragraph = in_array($tag, self::PARAGRAPH, true);
            $block = $paragraph || in_array($tag, self::BLOCK, true);

            if ($block) {
                self::newline($out);
            }
            if ($tag === 'li') {
                $out .= '- ';
            }
            if ($tag === 'hr') {
                $out .= "\n";
            }

            self::walk($child, $out, $pre || $tag === 'pre');

            if ($tag === 'td' || $tag === 'th') {
                $out .= ' ';
            }
            if ($block) {
                self::newline($out);
            }
            if ($paragraph) {
                $out .= "\n";
            }
        }
    }

    private static function newline(string &$out): void
    {
        $trimmed = rtrim($out, ' ');
        if ($trimmed !== '' && ! str_ends_with($trimmed, "\n")) {
            $out = $trimmed."\n";
        }
    }
}
