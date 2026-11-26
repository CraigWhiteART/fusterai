import { type ClassValue, clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';
import DOMPurify from 'dompurify';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

/**
 * Images may only come from our own attachment route (inline cid: images are
 * rewritten to it on ingest) or be embedded data: images. Anything remote is
 * dropped, so opening an email never loads a tracking pixel.
 */
const SAFE_IMG_SRC = /^(?:\/attachments\/\d+|data:image\/(?:png|jpe?g|gif|webp);base64,[a-z0-9+/=\s]+)$/i;

function isSafeImageSrc(src: string): boolean {
    const value = src.trim();
    if (SAFE_IMG_SRC.test(value)) return true;

    // Same-origin absolute form of the attachment route.
    if (typeof window !== 'undefined') {
        try {
            const url = new URL(value, window.location.origin);
            return (
                url.origin === window.location.origin && /^\/attachments\/\d+$/.test(url.pathname) && url.search === '' && url.hash === ''
            );
        } catch {
            return false;
        }
    }

    return false;
}

export interface SanitizeOptions {
    /**
     * Keep `/attachments/N` images (default true). The customer portal sets
     * false: that route needs a staff session, so the images would be broken.
     */
    attachmentImages?: boolean;
}

export function sanitizeHtml(html: string, options: SanitizeOptions = {}): string {
    const keepAttachments = options.attachmentImages ?? true;
    // Hooks are global in DOMPurify: add for this call only.
    DOMPurify.addHook('afterSanitizeAttributes', (node) => {
        if (node instanceof Element && node.nodeName === 'IMG') {
            const src = (node.getAttribute('src') ?? '').trim();
            if (!isSafeImageSrc(src) || (!keepAttachments && !src.toLowerCase().startsWith('data:'))) node.remove();
        }
    });

    try {
        return DOMPurify.sanitize(html, {
            ALLOWED_TAGS: [
                'p',
                'br',
                'b',
                'i',
                'u',
                'em',
                'strong',
                'a',
                'ul',
                'ol',
                'li',
                'blockquote',
                'code',
                'pre',
                'h1',
                'h2',
                'h3',
                'span',
                'div',
                'hr',
                'img',
                'table',
                'caption',
                'colgroup',
                'col',
                'thead',
                'tbody',
                'tfoot',
                'tr',
                'th',
                'td',
            ],
            ALLOWED_ATTR: ['href', 'target', 'rel', 'class', 'src', 'alt', 'title', 'width', 'height', 'colspan', 'rowspan', 'align'],
            ALLOW_DATA_ATTR: false,
        });
    } finally {
        DOMPurify.removeHook('afterSanitizeAttributes');
    }
}

export function getInitials(name?: string | null) {
    if (!name) return '?';

    const initials = name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');

    return initials || '?';
}
