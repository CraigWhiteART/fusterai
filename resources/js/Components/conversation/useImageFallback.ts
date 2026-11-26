import { useLayoutEffect, type RefObject } from 'react';

/**
 * Replaces images that fail to load inside rendered message bodies (`.prose`
 * under `ref`) with a neutral "Image not available" chip. Imported history
 * does not download attachments, so its inline images 404.
 *
 * One capture-phase listener on the container: `error` does not bubble, but it
 * does pass through ancestors while capturing, including for HTML set with
 * dangerouslySetInnerHTML and for quoted text revealed later.
 */
export function useImageFallback(ref: RefObject<HTMLElement | null>) {
    useLayoutEffect(() => {
        const root = ref.current;
        if (!root) return;

        const replace = (img: HTMLImageElement) => {
            if (!img.isConnected || !img.closest('.prose')) return;
            const chip = document.createElement('span');
            chip.className =
                'not-prose inline-flex items-center gap-1 rounded border border-dashed border-border bg-muted/50 px-2 py-1 text-[11px] text-muted-foreground align-middle';
            chip.textContent = img.alt ? `Image not available: ${img.alt}` : 'Image not available';
            chip.setAttribute('role', 'img');
            chip.setAttribute('aria-label', chip.textContent);
            img.replaceWith(chip);
        };

        const onError = (event: Event) => {
            if (event.target instanceof HTMLImageElement) replace(event.target);
        };
        root.addEventListener('error', onError, true);

        // Images that already failed before the listener was attached.
        root.querySelectorAll('img').forEach((img) => {
            if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) replace(img);
        });

        return () => root.removeEventListener('error', onError, true);
    });
}
