import { useCallback, useEffect, useRef, useState } from 'react';
import type { Editor } from '@tiptap/react';
import { cn } from '@/lib/utils';

// "/" saved-reply picker for the Tiptap composer: typing "/name" searches saved
// replies; ↑/↓ moves, Enter/Tab inserts, Escape closes.

export interface CannedResponse {
    id: number;
    name: string;
    content: string;
}

export function useCannedResponsePicker(mailboxId?: number) {
    const [results, setResults] = useState<CannedResponse[]>([]);
    const [activeIndex, setActiveIndex] = useState(0);
    const editorRef = useRef<Editor | null>(null);
    const slashPosRef = useRef<number | null>(null);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(
        () => () => {
            if (debounceRef.current) clearTimeout(debounceRef.current);
        },
        [],
    );

    const onEditorUpdate = useCallback(
        (editor: Editor) => {
            editorRef.current = editor;
            const { $from } = editor.state.selection;
            const textBefore = $from.parent.textContent.slice(0, $from.parentOffset);
            const match = textBefore.match(/(^|\s)\/([\w-]*)$/);

            if (match) {
                const query = match[2];
                const charsFromSlash = match[0].length - (match[1] ? match[1].length : 0);
                slashPosRef.current = $from.pos - charsFromSlash;
                setActiveIndex(0);
                if (debounceRef.current) clearTimeout(debounceRef.current);
                debounceRef.current = setTimeout(async () => {
                    try {
                        const mbParam = mailboxId ? `&mailbox_id=${mailboxId}` : '';
                        const res = await fetch(`/canned-responses/search?q=${encodeURIComponent(query)}${mbParam}`);
                        if (!res.ok) {
                            setResults([]);
                            return;
                        }
                        const data: CannedResponse[] = await res.json();
                        setResults(data);
                    } catch {
                        setResults([]);
                    }
                }, 150);
            } else {
                setResults([]);
                slashPosRef.current = null;
            }
        },
        [mailboxId],
    );

    const pickResponse = useCallback((r: CannedResponse) => {
        const editor = editorRef.current;
        if (!editor || slashPosRef.current === null) return;
        const to = editor.state.selection.from;
        editor.chain().deleteRange({ from: slashPosRef.current, to }).insertContent(r.content).focus().run();
        setResults([]);
        slashPosRef.current = null;
    }, []);

    const onKeyDown = useCallback(
        (e: KeyboardEvent) => {
            if (!results.length) return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                setActiveIndex((i) => (i + 1) % results.length);
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                setActiveIndex((i) => (i - 1 + results.length) % results.length);
            }
            if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                pickResponse(results[activeIndex]);
            }
            if (e.key === 'Escape') {
                setResults([]);
                slashPosRef.current = null;
            }
        },
        [results, activeIndex, pickResponse],
    );

    return { results, activeIndex, onEditorUpdate, pickResponse, onKeyDown };
}

export function CannedDropdown({
    results,
    activeIndex,
    onPick,
}: {
    results: CannedResponse[];
    activeIndex: number;
    onPick: (r: CannedResponse) => void;
}) {
    if (!results.length) return null;
    return (
        <div className="absolute bottom-full left-0 right-0 mb-1 bg-popover border border-border rounded-lg shadow-lg overflow-hidden z-50">
            {results.map((r, i) => (
                <button
                    key={r.id}
                    type="button"
                    onMouseDown={(e) => {
                        e.preventDefault();
                        onPick(r);
                    }}
                    className={cn(
                        'w-full text-left px-3 py-2 flex items-start gap-3 hover:bg-muted/60 transition-colors',
                        i === activeIndex && 'bg-muted/60',
                    )}
                >
                    <span className="inline-flex items-center rounded bg-primary/10 text-primary text-xs font-mono px-1.5 py-0.5 shrink-0 mt-0.5">
                        /{r.name}
                    </span>
                    <span className="text-xs text-muted-foreground line-clamp-2">{r.content}</span>
                </button>
            ))}
        </div>
    );
}
