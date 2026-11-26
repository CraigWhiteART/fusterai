import { useEffect } from 'react';
import { KeyboardIcon, XIcon } from 'lucide-react';

export default function ShortcutsModal({ onClose }: { onClose: () => void }) {
    useEffect(() => {
        function handler(e: KeyboardEvent) {
            if (e.key === 'Escape') onClose();
        }
        document.addEventListener('keydown', handler);
        return () => document.removeEventListener('keydown', handler);
    }, [onClose]);

    const sections = [
        {
            title: 'Conversation List',
            shortcuts: [
                { key: 'j', desc: 'Next conversation' },
                { key: 'k', desc: 'Previous conversation' },
                { key: 'Enter / o', desc: 'Open selected conversation' },
            ],
        },
        {
            title: 'Conversation View',
            shortcuts: [
                { key: 'r', desc: 'Focus reply editor' },
                { key: 'n', desc: 'Focus note editor' },
                { key: 'c', desc: 'Close conversation' },
                { key: 'Esc', desc: 'Blur focused element' },
            ],
        },
        {
            title: 'Reply Editor',
            shortcuts: [
                { key: '/', desc: 'Trigger canned response picker' },
                { key: '↑ / ↓', desc: 'Navigate canned responses' },
                { key: 'Tab / Enter', desc: 'Insert canned response' },
            ],
        },
        {
            title: 'Global',
            shortcuts: [{ key: '?', desc: 'Toggle this shortcuts panel' }],
        },
    ];

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-foreground/40"
            onClick={(e) => e.target === e.currentTarget && onClose()}
        >
            <div className="bg-card rounded-xl shadow-2xl border border-border w-full max-w-md mx-4 overflow-hidden">
                <div className="flex items-center justify-between px-5 py-4 border-b border-border">
                    <div className="flex items-center gap-2">
                        <KeyboardIcon className="h-4 w-4 text-muted-foreground" />
                        <h2 className="font-semibold text-sm">Keyboard Shortcuts</h2>
                    </div>
                    <button type="button" onClick={onClose} className="text-muted-foreground hover:text-foreground">
                        <XIcon className="h-4 w-4" />
                    </button>
                </div>
                <div className="px-5 py-4 space-y-5 max-h-[70vh] overflow-y-auto">
                    {sections.map((section) => (
                        <div key={section.title}>
                            <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground/60 mb-2">
                                {section.title}
                            </p>
                            <div className="space-y-1.5">
                                {section.shortcuts.map(({ key, desc }) => (
                                    <div key={key} className="flex items-center justify-between gap-4">
                                        <span className="text-sm text-muted-foreground">{desc}</span>
                                        <kbd className="inline-flex items-center rounded-md border border-border bg-muted px-2 py-0.5 text-[11px] font-mono font-medium text-foreground/80 shrink-0">
                                            {key}
                                        </kbd>
                                    </div>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}
