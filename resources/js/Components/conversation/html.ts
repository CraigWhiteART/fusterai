// Pure helpers for conversation bodies. Nothing here sanitizes: callers pass every
// fragment they render through sanitizeHtml() from '@/lib/utils'.

const HTML_HINT = /<\/?(?:p|br|div|ul|ol|li|strong|em|b|i|u|a|h[1-6]|blockquote|span|table)\b[^>]*>/i;

function escapeHtml(text: string): string {
    return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/**
 * Turn a draft into editor HTML. Drafts arrive as HTML or as plain text; plain
 * text must become paragraphs, because Tiptap parses its content as HTML and
 * would otherwise collapse every line break into one paragraph.
 */
export function draftToHtml(content: string): string {
    const text = content.replace(/\r\n?/g, '\n').trim();
    if (!text) return '';
    if (HTML_HINT.test(text)) return text;

    return text
        .split(/\n{2,}/)
        .map((para) => `<p>${escapeHtml(para.trim()).replace(/\n/g, '<br>')}</p>`)
        .join('');
}

/** True for '', '<p></p>', whitespace-only markup, and similar empty editor output. */
export function isEmptyHtml(html: string): boolean {
    if (!html) return true;
    if (/<img\b/i.test(html)) return false;
    return (
        html
            .replace(/<[^>]*>/g, '')
            .replace(/&nbsp;|&#160;/gi, ' ')
            .trim() === ''
    );
}

// ── Quoted history ────────────────────────────────────────────────────────────

export interface SplitBody {
    /** Raw HTML of the new content (unsanitized). */
    visible: string;
    /** Raw HTML of the quoted earlier messages, or null when none was found. */
    quoted: string | null;
}

/** Markers that wrap the quote itself: content after them means an inline reply. */
const WRAPPING_QUOTE_SELECTOR = ['.gmail_quote_container', '.gmail_quote', 'blockquote[type="cite"]', '.yahoo_quoted'].join(',');

/** Markers that start the quoted part: everything after them is history. */
const LEADING_QUOTE_SELECTOR = [
    '.moz-cite-prefix',
    '#divRplyFwdMsg',
    '#appendonsend',
    '.OutlookMessageHeader',
    '#OLK_SRC_BODY_SECTION',
].join(',');

const HEADER_LINE = /^(?:from|von|de|van|da|från|fra)\s*:/i;
const DATE_LINE = /^(?:sent|date|gesendet|datum|envoyé|enviado|verzonden|inviato|skickat|sendt)\s*:/i;
const ORIGINAL_MESSAGE =
    /^-{2,}\s*(?:original message|ursprüngliche nachricht|message d'origine|mensaje original|oorspronkelijk bericht)\s*-{2,}$/i;
const ATTRIBUTION = /(?:wrote|schrieb|a écrit|escribió|schreef|ha scritto|skrev)\s*:\s*$/i;

function textOf(node: Node | DocumentFragment): string {
    // trim() also strips non-breaking spaces.
    return (node.textContent ?? '').trim();
}

function hasContent(node: Node | DocumentFragment): boolean {
    if (textOf(node) !== '') return true;
    return node instanceof Element || node instanceof DocumentFragment ? !!node.querySelector?.('img') : false;
}

function before(a: Node, b: Node): boolean {
    return !!(a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING);
}

/** Outlook desktop starts the quote with a top-bordered div holding "From:". */
function outlookHeaderDiv(body: HTMLElement): Element | null {
    for (const div of Array.from(body.querySelectorAll('div[style*="border-top"]'))) {
        if (HEADER_LINE.test(textOf(div))) return div;
    }
    return null;
}

function contentAfter(doc: Document, node: Node): string {
    const range = doc.createRange();
    range.setStartAfter(node);
    range.setEndAfter(doc.body.lastChild ?? node);
    return range.toString().trim();
}

function domMarker(doc: Document): Node | null {
    const body = doc.body;
    const candidates: Node[] = [];

    const wrapping = body.querySelector(WRAPPING_QUOTE_SELECTOR);
    if (wrapping && contentAfter(doc, wrapping) === '') candidates.push(wrapping);

    let leading: Element | null = body.querySelector(LEADING_QUOTE_SELECTOR);
    // Outlook web puts an <hr> right before its reply header.
    if (leading?.previousElementSibling?.tagName === 'HR') leading = leading.previousElementSibling;
    if (leading) candidates.push(leading);

    const outlook = outlookHeaderDiv(body);
    if (outlook) candidates.push(outlook);

    if (candidates.length === 0) return null;
    return candidates.reduce((first, node) => (before(node, first) ? node : first));
}

function splitAtNode(doc: Document, marker: Node): SplitBody | null {
    const range = doc.createRange();
    range.setStartBefore(marker);
    range.setEndAfter(doc.body.lastChild ?? marker);
    const quoted = range.extractContents();

    if (!hasContent(doc.body) || !hasContent(quoted)) return null;

    const holder = doc.createElement('div');
    holder.appendChild(quoted);
    return { visible: doc.body.innerHTML, quoted: holder.innerHTML };
}

interface Line {
    start: number;
    text: string;
}

function decodeEntities(text: string): string {
    return text
        .replace(/&gt;|&#62;/gi, '>')
        .replace(/&lt;|&#60;/gi, '<')
        .replace(/&nbsp;|&#160;/gi, ' ')
        .replace(/&amp;/gi, '&');
}

/** Split raw HTML into visual lines (by <br>, </p>, </div>, or newlines in plain text). */
function linesOf(html: string): Line[] {
    const plain = !/<(?:br|p|div)\b/i.test(html);
    const separator = plain ? /\r?\n/g : /<br\s*\/?>|<\/(?:p|div|pre)>/gi;
    const lines: Line[] = [];
    let start = 0;
    let match: RegExpExecArray | null;
    const push = (end: number) =>
        lines.push({
            start,
            text: decodeEntities(html.slice(start, end).replace(/<[^>]*>/g, ''))
                .replace(/\s+/g, ' ')
                .trim(),
        });

    while ((match = separator.exec(html))) {
        push(match.index);
        start = match.index + match[0].length;
    }
    push(html.length);
    return lines;
}

/** Offset of an "-----Original Message-----" or "From: … / Sent: …" header block. */
function textHeaderOffset(lines: Line[]): number | null {
    for (let i = 0; i < lines.length; i++) {
        const text = lines[i].text;
        if (ORIGINAL_MESSAGE.test(text)) return i;
        if (HEADER_LINE.test(text)) {
            const next = lines
                .slice(i + 1)
                .filter((l) => l.text !== '')
                .slice(0, 4);
            if (next.some((l) => DATE_LINE.test(l.text))) return i;
        }
    }
    return null;
}

/** Offset of a trailing block of ">"-quoted lines, with its "On … wrote:" line. */
function trailingAngleQuote(lines: Line[]): number | null {
    let first = -1;
    let quotedLines = 0;
    let i = lines.length - 1;

    for (; i >= 0; i--) {
        const text = lines[i].text;
        if (text === '') continue;
        if (!text.startsWith('>')) break;
        quotedLines++;
        first = i;
    }

    if (quotedLines < 2) return null;
    if (i >= 0 && ATTRIBUTION.test(lines[i].text)) first = i;
    return first;
}

function splitByLines(html: string): SplitBody | null {
    const lines = linesOf(html);
    const index = textHeaderOffset(lines) ?? trailingAngleQuote(lines);
    if (index === null) return null;
    if (!lines.slice(0, index).some((l) => l.text !== '')) return null;

    const offset = lines[index].start;
    return { visible: html.slice(0, offset), quoted: html.slice(offset) };
}

/**
 * Separate quoted earlier messages from the new part of an email body, without
 * touching the stored body. Detects Gmail/Apple/Thunderbird/Yahoo quote markup,
 * Outlook reply headers, "Original Message" / "From: … Sent: …" text headers and
 * trailing ">"-quoted blocks. Returns `quoted: null` when nothing is found, when
 * the quote is the whole body (a bare forward), or when replies are interleaved.
 */
export function splitQuotedHtml(html: string): SplitBody {
    const none = { visible: html, quoted: null };
    if (!html || typeof DOMParser === 'undefined') return none;

    // DOMParser documents are inert: no scripts run and no images load.
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const marker = domMarker(doc);
    if (marker) return splitAtNode(doc, marker) ?? none;

    return splitByLines(html) ?? none;
}
